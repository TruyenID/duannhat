<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\TillTenderType;
use App\Models\User;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Omnify\Enums\TillSettlementKindEnum;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Plan 046 — Feature coverage for the shift handover / chain-of-shifts lifecycle.
 *
 * Covers the core scenarios from TESTS.md:
 *   - handover happy: settled + settlement_kind=handover + snapshot + chain_id,
 *     Till.current_session cleared (keeps the chain open)
 *   - open after handover continues the chain (same chain_id, chain_sequence+1)
 *   - final close ends the chain → next open starts a fresh chain
 *   - chainSummary: per-shift blocks + grand total = Σ snapshots; chain-of-one
 *   - handover a settled shift → 409 SHIFT_NOT_OPEN
 *   - chainSummary cross-branch → 404 (branch isolation)
 *   - G1: an abandoned mid-chain member (NULL snapshot) is excluded, no null-deref
 *
 * Self-contained (own beforeEach + uniquely-named chainAsCashier/chainOpenPayload
 * helpers) so it runs in isolation without depending on another file's globals.
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
        'slug' => 'chain-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-staff'],
        ['name' => 'Org Staff', 'level' => 10],
    );

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashier, $this->orgId);

    // Manager — only they may manual-settle an expired shift (plan-032).
    $managerRole = Role::firstOrCreate(
        ['slug' => 'shop-manager'],
        ['name' => 'Shop Manager', 'level' => 60],
    );
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    grantOrgAccess($this->manager, $this->orgId);
    $this->manager->refresh();

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();
    $this->jpy100 = Denomination::factory()->jpy100()->create();

    $this->cashTender = TillTenderType::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
    ]);
    $this->creditTender = TillTenderType::factory()->credit()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
    ]);
});

function chainAsCashier(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

function chainAsManager(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->manager)->withHeader('X-Shop-Slug', $t->shop->slug);
}

/** Manager recovery body for an expired shift (plan-032 manual-settle). */
function chainManualSettleBody(): array
{
    return [
        'closing_counts' => [
            ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
        ],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
        ],
        'manual_settle_reason' => 'Cashier left mid-shift; drawer counted by the manager.',
    ];
}

function chainOpenPayload(array $overrides = []): array
{
    return array_merge([
        'opening_counts' => [
            ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
        ],
        'opened_by_id' => (string) Str::uuid(),
    ], $overrides);
}

/** Settle body with matching counts (zero variance). */
function chainSettleBody(): array
{
    return [
        'closing_counts' => [
            ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
        ],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
        ],
    ];
}

// =========================================================================
//  Happy path — chain lifecycle
// =========================================================================

it('handover settles the shift, keeps the chain open, and writes a snapshot', function () {
    $session = chainAsCashier()
        ->postJson('/api/v1/pos/till/sessions', chainOpenPayload())
        ->json('data');

    expect((int) $session['chain_sequence'])->toBe(1);
    expect($session['chain_id'])->not->toBeNull();

    $handover = chainAsCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/handover",
        chainSettleBody(),
    )->assertOk()->json('data');

    expect($handover['status'])->toBe('settled');
    expect($handover['settlement_kind'])->toBe('handover');
    expect($handover['chain_id'])->toBe($session['chain_id']);

    // Snapshot persisted + till released (chain awaits continuation).
    $row = TillSession::findOrFail($session['id']);
    expect($row->settlement_snapshot)->toBeArray();
    expect($row->settlement_snapshot)->toHaveKeys(['cash', 'tax_breakdown', 'revenue']);

    $till = Till::where('branch_id', $this->shop->id)->firstOrFail();
    expect($till->current_session_id)->toBeNull();
});

it('continues the chain on the next open after a handover', function () {
    $s1 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/handover", chainSettleBody())->assertOk();

    $s2 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->assertCreated()->json('data');

    expect($s2['continues_chain'])->toBeTrue();
    expect($s2['chain_id'])->toBe($s1['chain_id']);
    expect((int) $s2['chain_sequence'])->toBe(2);
});

it('starts a fresh chain on the next open after a final close', function () {
    $s1 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    $close = chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/close", chainSettleBody())
        ->assertOk()->json('data');
    expect($close['settlement_kind'])->toBe('final');
    expect($close['chain_summary_ready'])->toBeTrue();

    $s2 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->assertCreated()->json('data');

    expect($s2['continues_chain'])->toBeFalse();
    expect($s2['chain_id'])->not->toBe($s1['chain_id']);
    expect((int) $s2['chain_sequence'])->toBe(1);
});

it('aggregates a 2-shift chain: per-shift blocks + grand total = Σ snapshots', function () {
    $s1 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/handover", chainSettleBody())->assertOk();
    $s2 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s2['id']}/close", chainSettleBody())->assertOk();

    $summary = chainAsCashier()
        ->getJson("/api/v1/pos/till/chains/{$s1['chain_id']}/summary")
        ->assertOk()->json('data');

    expect($summary['shifts'])->toHaveCount(2);
    expect((int) $summary['shifts'][0]['chain_sequence'])->toBe(1);
    expect((int) $summary['shifts'][1]['chain_sequence'])->toBe(2);
    expect($summary['chain_open'])->toBeFalse();

    // Grand total cash.counted == Σ of the two per-shift snapshots (to the yen).
    $sum = (float) $summary['shifts'][0]['cash']['counted'] + (float) $summary['shifts'][1]['cash']['counted'];
    expect((float) $summary['grand_total']['cash']['counted'])->toBe($sum);
});

it('renders a chain of one (final close, no handover) with a single block', function () {
    $s1 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/close", chainSettleBody())->assertOk();

    $summary = chainAsCashier()
        ->getJson("/api/v1/pos/till/chains/{$s1['chain_id']}/summary")
        ->assertOk()->json('data');

    expect($summary['shifts'])->toHaveCount(1);
    expect($summary['chain_open'])->toBeFalse();
});

// =========================================================================
//  Validation / authorization / edge
// =========================================================================

it('accepts a handover with an EMPTY tender_details (cash-only shift)', function () {
    // Regression: pos-web buildPayload filters out zero-value tenders, so a
    // cash-only shift posts tender_details: []. `required` rejected the empty
    // array → "The tender details field is required."; `present` allows it.
    $s = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');

    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s['id']}/handover", [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
        ],
        'tender_details' => [], // no non-cash tenders declared
    ])->assertOk()->assertJsonPath('data.settlement_kind', 'handover');
});

it('accepts a final close with an EMPTY tender_details (cash-only shift)', function () {
    $s = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');

    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s['id']}/close", [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
        ],
        'tender_details' => [],
    ])->assertOk()->assertJsonPath('data.settlement_kind', 'final');
});

it('rejects a handover on an already-settled shift with 409 SHIFT_NOT_OPEN', function () {
    $s1 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/close", chainSettleBody())->assertOk();

    chainAsCashier()
        ->postJson("/api/v1/pos/till/sessions/{$s1['id']}/handover", chainSettleBody())
        ->assertStatus(409)
        ->assertJsonPath('code', 'SHIFT_NOT_OPEN');
});

it('404s a chain summary requested from another branch', function () {
    $s1 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/close", chainSettleBody())->assertOk();

    $otherShop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'other-chain-shop',
        'is_active' => true,
    ]);

    chainAsCashier()->withHeader('X-Shop-Slug', $otherShop->slug)
        ->getJson("/api/v1/pos/till/chains/{$s1['chain_id']}/summary")
        ->assertStatus(404);
});

it('excludes an abandoned mid-chain member (NULL snapshot) from the aggregate (G1)', function () {
    $s1 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/handover", chainSettleBody())->assertOk();

    // Shift 2 opens (continues the chain) then is abandoned — it keeps chain_id
    // but never gets a settlement_snapshot (R5).
    $s2 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    TillSession::whereKey($s2['id'])->update([
        'status' => TillSessionStatusEnum::Abandoned->value,
        'abandoned_at' => now(),
    ]);

    $summary = chainAsCashier()
        ->getJson("/api/v1/pos/till/chains/{$s1['chain_id']}/summary")
        ->assertOk()->json('data');

    // Only the settled handover member counts; no null-deref on the abandoned row.
    expect($summary['shifts'])->toHaveCount(1);
    expect($summary['shifts'][0]['settlement_kind'])->toBe('handover');
});

// plan-046 — a manager-recovered (expired → manual-settle) shift is a REAL
// settlement holding real money, so it MUST appear in its chain's aggregate.
// It previously stamped only `status = settled`, leaving settlement_kind and
// settlement_snapshot null, so chainSummary()'s whereIn(settlement_kind,…)
// filter skipped it and the chain total silently lost that shift's cash,
// tax and revenue.
it('includes a manager manual-settled shift in the chain aggregate', function () {
    $s1 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/handover", chainSettleBody())->assertOk();

    // Shift 2 continues the chain, then dies and is expired by the reaper.
    $s2 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    TillSession::whereKey($s2['id'])->update([
        'status' => TillSessionStatusEnum::Expired->value,
        'expired_at' => now(),
        'expire_reason' => 'no_activity',
    ]);
    // expire() releases the till lock; mirror that so the fixture matches what
    // the scheduler actually leaves behind.
    Till::where('branch_id', $this->shop->id)->update(['current_session_id' => null]);

    // A manager reconciles it.
    chainAsManager()
        ->postJson("/api/v1/pos/till/sessions/{$s2['id']}/manual-settle", chainManualSettleBody())
        ->assertOk();

    $s2Fresh = TillSession::findOrFail($s2['id']);
    expect($s2Fresh->settlement_kind)->toBe(TillSettlementKindEnum::Final)
        ->and($s2Fresh->settlement_snapshot)->toBeArray()
        ->and($s2Fresh->settlement_snapshot)->toHaveKeys(['cash', 'tax_breakdown', 'revenue']);

    $summary = chainAsCashier()
        ->getJson("/api/v1/pos/till/chains/{$s1['chain_id']}/summary")
        ->assertOk()->json('data');

    // BOTH shifts must be in the aggregate — the recovered one included.
    expect($summary['shifts'])->toHaveCount(2);
    expect(collect($summary['shifts'])->pluck('settlement_kind')->all())
        ->toBe(['handover', 'final']);

    // And the grand total is the sum of both snapshots, not just shift 1's.
    $sumCounted = collect($summary['shifts'])->sum(fn ($s) => $s['cash']['counted']);
    expect((float) $summary['grand_total']['cash']['counted'])->toBe((float) $sumCounted);
});

// Recovering an expired shift must END the chain (it was never handed over),
// so the next open starts fresh rather than continuing through a dead shift.
it('ends the chain after a manual settle so the next open starts a new one', function () {
    $s1 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    chainAsCashier()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/handover", chainSettleBody())->assertOk();

    $s2 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->json('data');
    TillSession::whereKey($s2['id'])->update([
        'status' => TillSessionStatusEnum::Expired->value,
        'expired_at' => now(),
        'expire_reason' => 'no_activity',
    ]);
    // expire() releases the till lock; mirror that so the fixture matches what
    // the scheduler actually leaves behind.
    Till::where('branch_id', $this->shop->id)->update(['current_session_id' => null]);
    chainAsManager()
        ->postJson("/api/v1/pos/till/sessions/{$s2['id']}/manual-settle", chainManualSettleBody())
        ->assertOk();

    $s3 = chainAsCashier()->postJson('/api/v1/pos/till/sessions', chainOpenPayload())->assertCreated()->json('data');
    expect($s3['chain_id'])->not->toBe($s1['chain_id']);
    expect((int) $s3['chain_sequence'])->toBe(1);
});
