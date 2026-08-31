<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Till;
use App\Models\TillCashDenominationCount;
use App\Models\TillCashEvent;
use App\Models\TillSession;
use App\Models\User;
use App\Omnify\Enums\TillCountPhaseEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/*
 * Plan 032 T6.3 — POST /pos/till/sessions/{session}/manual-settle.
 * Reconcile an expired session post-hoc. Inherits close() math + adds
 * optional opening_counts_override + post_hoc_cash_events (Decision 8b).
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId, 'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'ms-shop',
        'is_active' => true,
    ]);

    $managerRole = Role::firstOrCreate(['slug' => 'shop-manager'], ['name' => 'Shop Manager', 'level' => 60]);
    $this->manager = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->manager->assignRole($managerRole, $this->orgId);
    $this->manager->refresh();

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();

    // Seed an expired session with opening counts.
    $till = Till::firstOrCreate(
        ['branch_id' => $this->shop->id, 'till_code' => 'MAIN'],
        ['default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0, 'is_active' => true,
            'brand_id' => $this->brand->id, 'organization_id' => $this->orgId],
    );
    $this->session = TillSession::create([
        'session_code' => 'SHIFT-EXP-001',
        'status' => TillSessionStatusEnum::Expired->value,
        'business_date' => now()->subDays(3)->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 50000,
        'opened_at' => now()->subDays(3),
        'expired_at' => now()->subHours(6),
        'expire_reason' => 'no_activity',
        'expire_threshold_hours' => 48,
        'till_id' => $till->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    TillCashDenominationCount::create([
        'session_id' => $this->session->id,
        'count_phase' => TillCountPhaseEnum::Opening->value,
        'quantity' => 5,
        'subtotal_amount' => 50000,
        'currency_code' => 'JPY',
        'denomination_value' => 10000,
        'denomination_kind' => 'note',
        'denomination_id' => $this->jpy10000->id,
    ]);
});

function manualSettlePayload(array $overrides = []): array
{
    return array_merge([
        'closing_counts' => [['denomination_id' => test()->jpy10000->id, 'quantity' => 5]],
        'tender_details' => [],
        'manual_settle_reason' => 'Cashier disappeared; drawer counted manually by manager 3 days later.',
    ], $overrides);
}

function actAsMs(): TestResponse|TestCase
{
    Sanctum::actingAs(test()->manager);

    return test()->withHeader('X-Shop-Slug', test()->shop->slug);
}

// =========================================================================
//  Happy path
// =========================================================================

it('settles an expired session with same-as-opening counts (zero variance)', function () {
    $response = actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload(),
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');
    expect($this->session->fresh()->status->value)->toBe('settled');
    expect((float) $response['counted_cash_amount'])->toBe(50000.0);
    expect((float) $response['cash_variance_amount'])->toBe(0.0);
});

it('settles an empty drawer sent as explicit zero-quantity counts (#1178)', function () {
    // A drawer counted and found empty is a real outcome on a stuck shift, but
    // `closing_counts` is required|min:1 — so the client cannot just drop the
    // zeros, it must send them. Assert that path works end-to-end: zero rows
    // persist, counted cash is 0, and the -50000 variance settles on the
    // closing_note reason.
    $response = actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload([
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 0],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 0],
            ],
            'closing_note' => 'Két rỗng — tiền đã được thu gom trước khi ca hết hiệu lực.',
        ]),
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');
    expect((float) $response['counted_cash_amount'])->toBe(0.0);
    expect((float) $response['cash_variance_amount'])->toBe(-50000.0);

    $closingRows = TillCashDenominationCount::where('session_id', $this->session->id)
        ->where('count_phase', TillCountPhaseEnum::Closing->value)
        ->get();
    expect($closingRows)->toHaveCount(2);
    expect((float) $closingRows->sum('subtotal_amount'))->toBe(0.0);
});

it('rejects manual-settle with an empty closing_counts array (422)', function () {
    // The failure admin-web hit in #1178: the grid rendered no inputs, so the
    // dialog posted an empty array. Keep it a validation error — settling a
    // shift with no count at all must never be silently accepted.
    actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload(['closing_counts' => []]),
    )->assertStatus(422)->assertJsonValidationErrors(['closing_counts']);

    expect($this->session->fresh()->status->value)->toBe('expired');
});

it('rejects manual-settle on an OPEN session (409 SHIFT_NOT_EXPIRED)', function () {
    $this->session->update(['status' => TillSessionStatusEnum::Open->value, 'expired_at' => null]);

    actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload(),
    )->assertStatus(409)->assertJson(['code' => 'SHIFT_NOT_EXPIRED']);
});

it('rejects manual-settle without manual_settle_reason (422)', function () {
    actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload(['manual_settle_reason' => null]),
    )->assertStatus(422)->assertJsonValidationErrors(['manual_settle_reason']);
});

it('rejects manual-settle when manual_settle_reason < 20 chars (422)', function () {
    actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload(['manual_settle_reason' => 'too short']),
    )->assertStatus(422)->assertJsonValidationErrors(['manual_settle_reason']);
});

// =========================================================================
//  Multi-tenant isolation (plan-audit high)
// =========================================================================

it('does NOT let a manager manual-settle an expired session in another org — 404', function () {
    $foreignOrgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $foreignOrgId, 'console_organization_id' => $foreignOrgId,
    ]);
    $foreignBrand = Brand::factory()->create(['console_organization_id' => $foreignOrgId]);
    $foreignShop = Branch::factory()->create([
        'console_organization_id' => $foreignOrgId,
        'console_brand_id' => $foreignBrand->console_brand_id,
        'slug' => 'ms-foreign-shop',
        'is_active' => true,
    ]);
    $foreignTill = Till::firstOrCreate(
        ['branch_id' => $foreignShop->id, 'till_code' => 'MAIN'],
        ['default_currency_code' => 'JPY', 'variance_tolerance_amount' => 0, 'is_active' => true,
            'brand_id' => $foreignBrand->id, 'organization_id' => $foreignOrgId],
    );
    // Expired + reconcilable: the ONLY thing that must stop settlement here is
    // the tenant boundary, so status/state are otherwise valid.
    $foreign = TillSession::create([
        'session_code' => 'SHIFT-FOREIGN-EXP-'.fake()->unique()->numberBetween(1000, 9999),
        'status' => TillSessionStatusEnum::Expired->value,
        'business_date' => now()->subDays(3)->toDateString(),
        'default_currency_code' => 'JPY',
        'opening_float_amount' => 50000,
        'opened_at' => now()->subDays(3),
        'expired_at' => now()->subHours(6),
        'expire_reason' => 'no_activity',
        'expire_threshold_hours' => 48,
        'till_id' => $foreignTill->id,
        'branch_id' => $foreignShop->id,
        'brand_id' => $foreignBrand->id,
        'organization_id' => $foreignOrgId,
    ]);

    // Manager (org A) keeps their own shop context but targets the foreign
    // expired session → 404 before any settlement math runs.
    actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$foreign->id}/manual-settle",
        manualSettlePayload(),
    )->assertStatus(404);

    // Untouched — still expired, never settled across the boundary.
    expect($foreign->fresh()->status->value)->toBe('expired');
});

// =========================================================================
//  Variance handling — inherits close() math (plan-audit medium)
// =========================================================================

it('rejects manual-settle when cash variance exceeds tolerance and no reason is given (422 VARIANCE_REASON_REQUIRED)', function () {
    // Expected cash = opening_float (50000) + no payments/events = 50000.
    // Counted = 4×10000 = 40000 → variance -10000, tolerance is 0, and there
    // is no closing_note nor any tender variance_reason → close()'s variance
    // guard must fire.
    $response = actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload([
            'closing_counts' => [['denomination_id' => $this->jpy10000->id, 'quantity' => 4]],
        ]),
    )->assertStatus(422);

    $response->assertJson([
        'code' => 'VARIANCE_REASON_REQUIRED',
        'cash_missing_reason' => true,
    ]);

    // Session must NOT flip to settled when the guard rejects.
    expect($this->session->fresh()->status->value)->toBe('expired');
});

it('accepts the same over-tolerance variance once a closing_note reason is supplied (settles)', function () {
    // Same -10000 variance as above, but now with a closing_note reason — the
    // exact field close() accepts to satisfy the cash-variance guard.
    $response = actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload([
            'closing_counts' => [['denomination_id' => $this->jpy10000->id, 'quantity' => 4]],
            'closing_note' => 'Drawer short 10,000 JPY — cashier confirmed a cash drop was never recorded.',
        ]),
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');
    expect((float) $response['counted_cash_amount'])->toBe(40000.0);
    expect((float) $response['cash_variance_amount'])->toBe(-10000.0);
    expect($this->session->fresh()->status->value)->toBe('settled');
});

// =========================================================================
//  Decision 8b — overrides
// =========================================================================

it('opening_counts_override replaces opening counts AND emits a separate audit row', function () {
    actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload([
            'opening_counts_override' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 3],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
            ],
            // Match the override so variance = 0 — keeps the test focused on
            // the override mechanism, not variance-reason inheritance.
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 3],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
            ],
        ]),
    )->assertOk();

    $session = $this->session->fresh();
    expect((float) $session->opening_float_amount)->toBe(35000.0); // 3×10000 + 5×1000

    $overrideAudit = DB::table('audit_logs')
        ->where('auditable_id', $this->session->id)
        ->where('action', 'till_session_opening_overridden')
        ->first();
    expect($overrideAudit)->not->toBeNull();
    $payload = json_decode($overrideAudit->metadata, true);
    expect($payload['before'])->not->toBeEmpty();
    expect($payload['after'])->toHaveCount(2);
    expect((float) $payload['new_opening_float_amount'])->toBe(35000.0);
});

it('post_hoc_cash_events insert with manual_adjustment=true + recorded_by manager', function () {
    // opening 50000 + paid_in 2000 - paid_out 5000 = expected 47000. Use a
    // closing_note as the cash variance reason since counted (40000) differs.
    actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload([
            'post_hoc_cash_events' => [
                ['event_type' => 'paid_out', 'amount' => 5000, 'currency_code' => 'JPY', 'reason' => 'Tip jar'],
                ['event_type' => 'paid_in', 'amount' => 2000, 'currency_code' => 'JPY', 'reason' => 'Float top-up'],
            ],
            'closing_counts' => [['denomination_id' => $this->jpy10000->id, 'quantity' => 4]],
            'closing_note' => 'Manager reconstructed cash events from receipts.',
        ]),
    )->assertOk();

    $events = TillCashEvent::where('session_id', $this->session->id)->where('manual_adjustment', true)->get();
    expect($events)->toHaveCount(2);
    foreach ($events as $e) {
        expect($e->performed_by_id)->toBe((string) $this->manager->id);
        expect((bool) $e->manual_adjustment)->toBeTrue();
    }
});

it('audit row carries opening_counts_overridden + post_hoc_cash_events flags', function () {
    actAsMs()->postJson(
        "/api/v1/pos/till/sessions/{$this->session->id}/manual-settle",
        manualSettlePayload([
            'opening_counts_override' => [['denomination_id' => $this->jpy10000->id, 'quantity' => 5]],
            'post_hoc_cash_events' => [['event_type' => 'paid_in', 'amount' => 1000, 'currency_code' => 'JPY']],
            // counted must include the 1000 from paid_in to balance:
            // expected = 50000 + 1000 = 51000. Closing counts: 5×10000 + 1×1000 = 51000.
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 5],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 1],
            ],
        ]),
    )->assertOk();

    $audit = DB::table('audit_logs')
        ->where('auditable_id', $this->session->id)
        ->where('action', 'till_session_manual_settled')
        ->first();
    expect($audit)->not->toBeNull();
    $payload = json_decode($audit->metadata, true);
    expect($payload['prior_status'])->toBe('expired');
    expect($payload['manager_id'])->toBe((string) $this->manager->id);
    expect($payload['opening_counts_overridden'])->toBeTrue();
    expect($payload['post_hoc_cash_events'])->toBe(1);
});
