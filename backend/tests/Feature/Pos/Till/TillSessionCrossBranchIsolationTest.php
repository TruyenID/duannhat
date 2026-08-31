<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Plan 030 — Cross-branch (multi-tenant) isolation (test-gap audit, finding 2).
 *
 * TESTS.md "Authorization" required that a cashier resolving branch B cannot
 * touch a session belonging to branch A — 404/403, "not just hidden". The only
 * cross-branch coverage before this file was device-token BRANCH_MISMATCH
 * (TillSessionDraftAuthTest); the SSO-cashier path through
 * TillSessionController::ensureShopMatch() (404 to avoid leaking existence
 * across branches) had no test.
 *
 * Both branches live in the SAME org, so the org check alone would pass — this
 * pins the branch_id-vs-shop_id operational invariant specifically. Every
 * session-scoped verb is asserted (show / reconciliation / cash-events /
 * draft / close / abandon), plus a same-branch positive control so the test
 * fails loudly if the setup (not the guard) is what produced the 404.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
    ]);

    // Two branches in the SAME org — A owns the session, B is where the cashier
    // resolves via X-Shop-Slug.
    $this->branchA = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'branch-a',
        'is_active' => true,
    ]);
    $this->branchB = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'branch-b',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-staff'],
        ['name' => 'Org Staff', 'level' => 10],
    );

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashier, $this->orgId);

    // A denomination so the close request passes shape validation and reaches
    // the branch guard (validation runs before ensureShopMatch).
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();

    // An open session on branch A.
    $tillA = Till::factory()->create([
        'till_code' => 'MAIN',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchA->id,
    ]);
    $this->sessionA = TillSession::factory()->open()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branchA->id,
        'till_id' => $tillA->id,
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 100_000,
    ]);
    $tillA->update(['current_session_id' => $this->sessionA->id]);
});

/** Cashier resolving branch B (NOT the session's branch A). */
function actingAtBranchB(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->branchB->slug);
}

it('404s a cross-branch GET session detail', function () {
    actingAtBranchB()
        ->getJson("/api/v1/pos/till/sessions/{$this->sessionA->id}")
        ->assertNotFound();
});

it('404s a cross-branch GET reconciliation', function () {
    actingAtBranchB()
        ->getJson("/api/v1/pos/till/sessions/{$this->sessionA->id}/reconciliation")
        ->assertNotFound();
});

it('404s a cross-branch cash-event POST', function () {
    actingAtBranchB()
        ->postJson("/api/v1/pos/till/sessions/{$this->sessionA->id}/cash-events", [
            'event_type' => 'paid_in',
            'amount' => 1000,
            'reason' => 'poke another branch',
        ])
        ->assertNotFound();
});

it('404s a cross-branch draft PATCH', function () {
    actingAtBranchB()
        ->patchJson("/api/v1/pos/till/sessions/{$this->sessionA->id}/draft", [
            'closing_counts' => [],
            'tender_details' => [],
        ])
        ->assertNotFound();
});

it('404s a cross-branch close POST', function () {
    actingAtBranchB()
        ->postJson("/api/v1/pos/till/sessions/{$this->sessionA->id}/close", [
            'closing_counts' => [
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 1],
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ],
        ])
        ->assertNotFound();

    // The session on branch A must be untouched — still open.
    expect($this->sessionA->fresh()->status->value ?? $this->sessionA->fresh()->status)
        ->toBe('open');
});

it('404s a cross-branch abandon POST', function () {
    actingAtBranchB()
        ->postJson("/api/v1/pos/till/sessions/{$this->sessionA->id}/abandon", [
            'abandon_reason' => 'wrong branch',
        ])
        ->assertNotFound();

    expect($this->sessionA->fresh()->status->value ?? $this->sessionA->fresh()->status)
        ->toBe('open');
});

it('positive control — the SAME branch can read its own session (proves the 404 is the branch guard, not a broken setup)', function () {
    test()->actingAs($this->cashier)
        ->withHeader('X-Shop-Slug', $this->branchA->slug)
        ->getJson("/api/v1/pos/till/sessions/{$this->sessionA->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $this->sessionA->id);
});
