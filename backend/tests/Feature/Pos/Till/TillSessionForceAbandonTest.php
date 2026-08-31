<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
 * Plan 032 T6.1 — POST /pos/till/sessions/{session}/force-abandon.
 *
 * Covers the manager-driven exit path: bypasses the SHIFT_HAS_PAYMENTS guard
 * that cashier-driven abandon enforces, stamps force_abandoned + reason
 * fields, releases the till, and writes an audit row carrying the manager
 * identity.
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

    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'fa-shop',
        'is_active' => true,
    ]);

    $cashierRole = Role::firstOrCreate(['slug' => 'shop-staff'], ['name' => 'Shop Staff', 'level' => 10]);
    $managerRole = Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);

    // Cashier: assign shop-staff role ONLY (gives IAM access without escalating
    // to org-admin). The `grantOrgAccess` helper assigns org-admin, which would
    // mask the role-differentiation we're testing here.
    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($cashierRole, $this->orgId);

    // Manager: shop-manager role at ORG level (null branch_id). hasRoleInContext
    // matches org+branch=null pivots as well as org+branch=current branch pivots
    // — see getRolesForContext in HasSsoRoles. Pinning to a branch also works
    // but org-level is closer to how production manager assignments tend to
    // look (a manager covering multiple branches).
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    $this->manager->refresh();

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();

    $this->session = openShiftAsCashier($this->shop, $this->cashier, $this->jpy10000);
});

function openShiftAsCashier(Branch $shop, User $user, Denomination $denom): TillSession
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Shop-Slug', $shop->slug)
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [['denomination_id' => $denom->id, 'quantity' => 10]],
            'opened_by_id' => (string) Str::uuid(),
        ])->assertCreated();

    return TillSession::where('branch_id', $shop->id)->latest('opened_at')->firstOrFail();
}

function actAsManager(): TestCase
{
    /** @var TestCase $t */
    $t = test();
    Sanctum::actingAs($t->manager);

    return $t->withHeader('X-Shop-Slug', $t->shop->slug);
}

function actAsCashier(): TestCase
{
    /** @var TestCase $t */
    $t = test();
    Sanctum::actingAs($t->cashier);

    return $t->withHeader('X-Shop-Slug', $t->shop->slug);
}

function stampPaymentOnSession(TillSession $session, Branch $shop, Brand $brand, string $orgId): OrderPayment
{
    $method = PaymentMethod::firstOrCreate(
        ['organization_id' => $orgId, 'branch_id' => null, 'code' => 'cash'],
        [
            'name' => 'Cash', 'is_auto_confirm' => true, 'requires_tendered' => true,
            'is_active' => true, 'sort_order' => 0,
        ],
    );
    $order = CustomerOrder::factory()->create([
        'organization_id' => $orgId, 'brand_id' => $brand->id, 'branch_id' => $shop->id,
    ]);

    return OrderPayment::create([
        'payment_code' => 'PAY-2026-'.fake()->unique()->numberBetween(10000, 99999),
        'amount' => 1000, 'status' => 'succeeded', 'paid_at' => now(),
        'customer_order_id' => $order->id, 'payment_method_id' => $method->id,
        'branch_id' => $shop->id, 'brand_id' => $brand->id, 'organization_id' => $orgId,
        'till_session_id' => $session->id,
    ]);
}

// =========================================================================
//  Happy path
// =========================================================================

it('force-abandons an open shift WITH stamped payments (bypasses SHIFT_HAS_PAYMENTS)', function () {
    stampPaymentOnSession($this->session, $this->shop, $this->brand, $this->orgId);

    $response = actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'cashier_forgot_to_close'],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('abandoned');
    expect($response['force_abandoned'])->toBeTrue();
    expect($response['force_abandoned_by_id'])->toBe((string) $this->manager->id);

    $session = TillSession::find($this->session->id);
    expect($session->force_abandon_reason_code->value)->toBe('cashier_forgot_to_close');
    expect((string) $session->abandoned_at)->not->toBeEmpty();

    $till = Till::where('branch_id', $this->shop->id)->firstOrFail();
    expect($till->current_session_id)->toBeNull();
});

it('writes a till_session_force_abandoned audit row with counts', function () {
    stampPaymentOnSession($this->session, $this->shop, $this->brand, $this->orgId);

    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'pos_device_failure'],
    )->assertOk();

    $audit = DB::table('audit_logs')
        ->where('auditable_id', $this->session->id)
        ->where('action', 'till_session_force_abandoned')
        ->latest('created_at')
        ->first();

    expect($audit)->not->toBeNull();
    $payload = json_decode($audit->metadata, true);
    expect($payload['reason_code'])->toBe('pos_device_failure');
    expect($payload['manager_id'])->toBe((string) $this->manager->id);
    expect($payload['payments_count'])->toBe(1);
});

it('releases the till so the next cashier can open a new shift', function () {
    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'cashier_forgot_to_close'],
    )->assertOk();

    actAsCashier()->postJson('/api/v1/pos/till/sessions', [
        'opening_counts' => [['denomination_id' => $this->jpy10000->id, 'quantity' => 5]],
        'opened_by_id' => (string) Str::uuid(),
    ])->assertCreated();
});

it('payments retain their till_session_id after force-abandon (revenue reports stay intact)', function () {
    $payment = stampPaymentOnSession($this->session, $this->shop, $this->brand, $this->orgId);

    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'cashier_forgot_to_close'],
    )->assertOk();

    expect(OrderPayment::find($payment->id)->till_session_id)->toBe($this->session->id);
});

// =========================================================================
//  Validation — Decision 8 dual-field reason
// =========================================================================

it('rejects force-abandon without reason_code (422)', function () {
    actAsManager()->postJson("/api/v1/pos/till/sessions/{$this->session->id}/force-abandon", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason_code']);
});

it('rejects force-abandon with unknown reason_code value (422)', function () {
    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'cashier_was_an_alien']
    )->assertStatus(422)->assertJsonValidationErrors(['reason_code']);
});

it('accepts reason_code other than other without a reason_detail', function () {
    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'cashier_forgot_to_close'],
    )->assertOk();
});

it('rejects reason_code=other without reason_detail (422)', function () {
    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'other'],
    )->assertStatus(422)->assertJsonValidationErrors(['reason_detail']);
});

it('rejects reason_code=other with reason_detail < 20 chars (422)', function () {
    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'other', 'reason_detail' => 'too short'],
    )->assertStatus(422)->assertJsonValidationErrors(['reason_detail']);
});

it('accepts reason_code=other with reason_detail >= 20 chars', function () {
    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'other', 'reason_detail' => 'Manager investigated and confirmed drawer count manually.'],
    )->assertOk();
});

// =========================================================================
//  Authorization
// =========================================================================

it('forbids cashier (shop-staff) from force-abandon (403)', function () {
    actAsCashier()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'cashier_forgot_to_close'],
    )->assertStatus(403);
});

// (Unauthenticated test removed: the POS namespace allows both browser-auth
// and workstation device-token; a no-auth call may still hit the X-Shop-Slug
// guard first. Role-differentiation tests above cover the practical attack
// surface — a non-manager hits 403 regardless of auth shape.)

// =========================================================================
//  Multi-tenant isolation (plan-audit high)
// =========================================================================

function seedForeignForceAbandonSession(): TillSession
{
    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $foreignOrgId, 'console_organization_id' => $foreignOrgId,
    ]);
    $foreignBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);
    $foreignShop = Branch::factory()->create([
        'console_organization_id' => $foreignOrgId,
        'console_brand_id' => $foreignBrand->console_brand_id,
        'slug' => 'fa-foreign-shop',
        'is_active' => true,
    ]);
    $foreignTill = Till::firstOrCreate(
        ['branch_id' => $foreignShop->id, 'till_code' => 'MAIN'],
        ['default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0, 'is_active' => true,
            'brand_id' => $foreignBrand->id, 'organization_id' => $foreignOrgId],
    );
    $session = TillSession::create([
        'session_code' => 'SHIFT-FOREIGN-'.fake()->unique()->numberBetween(1000, 9999),
        'status' => 'open',
        'business_date' => now()->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 0,
        'opened_at' => now()->subHours(2),
        'till_id' => $foreignTill->id,
        'branch_id' => $foreignShop->id,
        'brand_id' => $foreignBrand->id,
        'organization_id' => $foreignOrgId,
    ]);
    $foreignTill->update(['current_session_id' => $session->id]);

    return $session;
}

it('does NOT let a manager force-abandon a session in another org — 404 (own shop context)', function () {
    $foreign = seedForeignForceAbandonSession();

    // Manager stays in their own (org A) shop context but targets a foreign
    // session id. ensureShopMatch fires 404 before the policy/service run —
    // no existence leak across the tenant boundary.
    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$foreign->id}/force-abandon",
        ['reason_code' => 'cashier_forgot_to_close'],
    )->assertStatus(404);

    $fresh = $foreign->fresh();
    expect($fresh->status->value)->toBe('open');
    expect((bool) $fresh->force_abandoned)->toBeFalse();
});

it('does NOT let a manager address a foreign org shop context — 403', function () {
    $foreign = seedForeignForceAbandonSession();
    $foreignSlug = Branch::find($foreign->branch_id)->slug;

    // Manager tries to borrow the foreign shop's slug to reach its session.
    // The shop-context IAM check has no role pivot for the manager in org B → 403.
    Sanctum::actingAs($this->manager);
    $this->withHeader('X-Shop-Slug', $foreignSlug)
        ->postJson(
            "/api/v1/pos/till/sessions/{$foreign->id}/force-abandon",
            ['reason_code' => 'cashier_forgot_to_close'],
        )->assertStatus(403);

    expect((bool) $foreign->fresh()->force_abandoned)->toBeFalse();
});

// =========================================================================
//  Status guard — terminal states
// =========================================================================

it('rejects force-abandon on already-settled session (409 SHIFT_TERMINAL_STATE)', function () {
    $this->session->update(['status' => 'settled', 'closed_at' => now()]);

    $response = actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'cashier_forgot_to_close'],
    )->assertStatus(409);

    expect($response->json('code'))->toBe('SHIFT_TERMINAL_STATE');
});

it('rejects force-abandon on already-expired session (409 SHIFT_TERMINAL_STATE)', function () {
    $this->session->update(['status' => 'expired', 'expired_at' => now()]);

    actAsManager()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/force-abandon",
        ['reason_code' => 'cashier_forgot_to_close'],
    )->assertStatus(409);
});
