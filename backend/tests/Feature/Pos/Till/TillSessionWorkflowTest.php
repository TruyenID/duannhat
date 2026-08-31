<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\TillTenderType;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Plan 030 — Feature coverage for the cashier-shift workflow.
 *
 * Covers the critical paths from TESTS.md:
 *   - open shift happy path → opening_float = Σ(value × qty), Till.current_session set
 *   - SHIFT_ALREADY_OPEN 409 on second open
 *   - cash-event paid_out shifts expected_cash
 *   - close happy: settled status, Till.current_session cleared, denomination counts written
 *   - VARIANCE_REASON_REQUIRED 422 when variance > tolerance with no reason
 *   - abandon happy + SHIFT_HAS_PAYMENTS 409 once a payment is stamped
 *   - GET /pos/till/{current,denominations,tender-types} all return 200
 *   - opener="Khác" + opener_name persists; missing both → 422
 *
 * Excludes (out of scope for the first slice):
 *   - Multi-currency drawer, role-matrix authorization breadth, browser scenarios.
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
        'slug' => 'till-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-staff'],
        ['name' => 'Org Staff', 'level' => 10],
    );

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashier, $this->orgId);

    // Seed minimal denominations (3 JPY notes/coins) + 2 tender types.
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

function actingAsCashier(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

function openShiftPayload(array $overrides = []): array
{
    return array_merge([
        'opening_counts' => [
            ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
        ],
        'opened_by_id' => (string) Str::uuid(),
    ], $overrides);
}

// =========================================================================
//  Happy path — open + reads
// =========================================================================

it('opens a shift, stores opening counts, and stamps the till', function () {
    $response = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->assertStatus(201);

    $data = $response->json('data');
    expect($data['status'])->toBe('open');
    expect((float) $data['opening_float_amount'])->toBe(10 * 10000 + 5 * 1000.0);
    expect($data['session_code'])->toStartWith('SHIFT-');

    $till = Till::where('branch_id', $this->shop->id)->firstOrFail();
    expect($till->current_session_id)->toBe($data['id']);
    expect($data['opening_counts'])->toHaveCount(2);
});

it('derives the next SHIFT number globally and ignores non-numeric suffixes', function () {
    $today = now()->format('Ymd');

    // A DIFFERENT tenant already opened today's shifts. The pre-existing codes
    // include numbers AND seed-style non-numeric suffixes (SHIFT-…-CLOSING,
    // SHIFT-…-OPEN1). Two bugs this guards against:
    //   1. Per-org counting restarted at 001 for this org → collided with the
    //      GLOBAL unique constraint (1062 Duplicate entry).
    //   2. orderBy(session_code desc) picked 'OPEN1' (sorts after digits) and
    //      (int)'OPEN1' = 0 → reset the sequence back to 001.
    // The generator must take MAX of the NUMERIC suffix only → next is 002.
    $otherOrgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $otherOrgId, 'console_organization_id' => $otherOrgId]);
    $otherBrand = Brand::factory()->create(['console_organization_id' => $otherOrgId]);
    $otherBranch = Branch::factory()->create([
        'console_organization_id' => $otherOrgId,
        'console_brand_id' => $otherBrand->console_brand_id,
        'is_active' => true,
    ]);
    $otherTill = Till::factory()->create([
        'organization_id' => $otherOrgId,
        'brand_id' => $otherBrand->id,
        'branch_id' => $otherBranch->id,
    ]);
    foreach (["SHIFT-{$today}-001", "SHIFT-{$today}-CLOSING", "SHIFT-{$today}-OPEN1"] as $code) {
        TillSession::factory()->create([
            'session_code' => $code,
            'organization_id' => $otherOrgId,
            'brand_id' => $otherBrand->id,
            'branch_id' => $otherBranch->id,
            'till_id' => $otherTill->id,
        ]);
    }

    $data = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->assertCreated()
        ->json('data');

    expect($data['session_code'])->toBe("SHIFT-{$today}-002");
});

it('returns the current till and open session via GET /pos/till/current', function () {
    actingAsCashier()->postJson('/api/v1/pos/till/sessions', openShiftPayload())->assertCreated();

    $response = actingAsCashier()->getJson('/api/v1/pos/till/current')->assertOk()->json('data');
    expect($response['open_session'])->not->toBeNull();
    expect($response['open_session']['opening_counts'])->toHaveCount(2);
    expect($response['open_session']['closing_counts'])->toBe([]);
    expect($response['till']['till_code'])->toBe('MAIN');
});

it('returns saved draft closing counts via GET /pos/till/current', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->assertCreated()
        ->json('data');

    actingAsCashier()
        ->patchJson("/api/v1/pos/till/sessions/{$session['id']}/draft", [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 8],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 7],
            ],
            'tender_details' => [],
        ])
        ->assertOk();

    $current = actingAsCashier()
        ->getJson('/api/v1/pos/till/current')
        ->assertOk()
        ->json('data.open_session');

    expect($current['status'])->toBe('closing');
    expect(collect($current['closing_counts'])->pluck('quantity', 'denomination_id')->all())
        ->toBe([
            $this->jpy10000->id => 8,
            $this->jpy1000->id => 7,
        ]);
});

it('lists JPY denominations on /pos/till/denominations', function () {
    $response = actingAsCashier()
        ->getJson('/api/v1/pos/till/denominations?currency=JPY')
        ->assertOk()
        ->json('data');
    expect(count($response))->toBeGreaterThanOrEqual(3);
});

it('lists active branch-scoped tender types', function () {
    $response = actingAsCashier()
        ->getJson('/api/v1/pos/till/tender-types')
        ->assertOk()
        ->json('data');
    $keys = collect($response)->pluck('tender_key')->all();
    expect($keys)->toContain('cash');
    expect($keys)->toContain('credit');
});

// =========================================================================
//  Validation / error
// =========================================================================

it('returns 409 SHIFT_ALREADY_OPEN on second open', function () {
    actingAsCashier()->postJson('/api/v1/pos/till/sessions', openShiftPayload())->assertCreated();

    $response = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->assertStatus(409);
    expect($response->json('code'))->toBe('SHIFT_ALREADY_OPEN');
});

it('rejects missing opening_counts with 422', function () {
    actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', ['opened_by_id' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('errors.opening_counts.0', fn ($msg) => is_string($msg));
});

it('rejects negative quantity with 422', function () {
    actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload([
            'opening_counts' => [
                ['denomination_id' => $this->jpy1000->id, 'quantity' => -1],
            ],
        ]))
        ->assertStatus(422);
});

it('defaults opened_by_id to the authenticated SSO user when both fields omitted', function () {
    $response = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [['denomination_id' => $this->jpy1000->id, 'quantity' => 5]],
            // both opened_by_id and opener_name absent — "Tôi" UI mode.
        ])
        ->assertStatus(201)
        ->json('data');
    expect($response['opened_by_id'])->toBe($this->cashier->id);
    expect($response['opener_name'])->toBeNull();
});

it('persists opener_name when operator is "Khác"', function () {
    $response = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [['denomination_id' => $this->jpy1000->id, 'quantity' => 5]],
            'opener_name' => 'Tanaka Yuki',
        ])
        ->assertCreated()
        ->json('data');
    expect($response['opener_name'])->toBe('Tanaka Yuki');
});

// =========================================================================
//  Cash events
// =========================================================================

it('records a paid_out cash event and reflects it in expected_cash', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    actingAsCashier()
        ->postJson("/api/v1/pos/till/sessions/{$session['id']}/cash-events", [
            'event_type' => 'paid_out',
            'amount' => 2000,
            'reason' => 'Supply purchase',
        ])
        ->assertStatus(201);

    $recon = actingAsCashier()
        ->getJson("/api/v1/pos/till/sessions/{$session['id']}/reconciliation")
        ->assertOk()
        ->json('data');
    expect((float) $recon['cash']['paid_out'])->toBe(2000.0);
    // expected_cash = opening_float + 0 cash_sales + 0 paid_in − 2000 paid_out
    expect((float) $recon['cash']['expected_cash'])->toBe(
        (float) $recon['cash']['opening_float'] - 2000.0,
    );
});

// Issue #530 — safe-flow events move real cash in/out of the drawer, so they
// MUST be folded into expected_cash. pickup_to_safe removes cash (drawer down),
// loan_from_safe adds it (drawer up). Omitting them made the shift report
// short/over by the moved amount.
it('reflects pickup_to_safe in expected_cash (#530)', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    actingAsCashier()
        ->postJson("/api/v1/pos/till/sessions/{$session['id']}/cash-events", [
            'event_type' => 'pickup_to_safe',
            'amount' => 5000,
            'reason' => 'Drop excess cash to safe',
        ])
        ->assertStatus(201);

    $recon = actingAsCashier()
        ->getJson("/api/v1/pos/till/sessions/{$session['id']}/reconciliation")
        ->assertOk()
        ->json('data');

    expect((float) $recon['cash']['pickup_to_safe'])->toBe(5000.0);
    expect((float) $recon['cash']['expected_cash'])->toBe(
        (float) $recon['cash']['opening_float'] - 5000.0,
    );
});

it('reflects loan_from_safe in expected_cash (#530)', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    actingAsCashier()
        ->postJson("/api/v1/pos/till/sessions/{$session['id']}/cash-events", [
            'event_type' => 'loan_from_safe',
            'amount' => 3000,
            'reason' => 'Top up change from safe',
        ])
        ->assertStatus(201);

    $recon = actingAsCashier()
        ->getJson("/api/v1/pos/till/sessions/{$session['id']}/reconciliation")
        ->assertOk()
        ->json('data');

    expect((float) $recon['cash']['loan_from_safe'])->toBe(3000.0);
    expect((float) $recon['cash']['expected_cash'])->toBe(
        (float) $recon['cash']['opening_float'] + 3000.0,
    );
});

it('rejects cash-event amount ≤ 0', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    actingAsCashier()
        ->postJson("/api/v1/pos/till/sessions/{$session['id']}/cash-events", [
            'event_type' => 'paid_in',
            'amount' => 0,
            'reason' => 'Test',
        ])
        ->assertStatus(422);
});

it('rejects cash-event without reason', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    actingAsCashier()
        ->postJson("/api/v1/pos/till/sessions/{$session['id']}/cash-events", [
            'event_type' => 'paid_out',
            'amount' => 500,
        ])
        ->assertStatus(422);
});

// =========================================================================
//  Close
// =========================================================================

it('closes a shift with matching counts and zero variance', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    $opening = (float) $session['opening_float_amount']; // 105_000

    $response = actingAsCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
            ],
        ],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');
    expect((float) $response['counted_cash_amount'])->toBe($opening);
    expect((float) $response['cash_variance_amount'])->toBe(0.0);
    expect($response['opening_counts'])->toHaveCount(2);
    expect($response['closing_counts'])->toHaveCount(2);
    expect(collect($response['closing_counts'])->pluck('quantity', 'denomination_id')->all())
        ->toBe([
            $this->jpy10000->id => 10,
            $this->jpy1000->id => 5,
        ]);

    $shown = actingAsCashier()
        ->getJson("/api/v1/pos/till/sessions/{$session['id']}")
        ->assertOk()
        ->json('data');
    expect(collect($shown['closing_counts'])->pluck('quantity', 'denomination_id')->all())
        ->toBe(collect($response['closing_counts'])->pluck('quantity', 'denomination_id')->all());

    $till = Till::where('branch_id', $this->shop->id)->firstOrFail();
    expect($till->current_session_id)->toBeNull();
});

it('adds the odd-change adjustment to counted cash so it offsets a denomination shortfall', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    // Denominations total 104,000 — 1,000 short of the 105,000 opening float.
    // Without the adjustment this would be a −1,000 variance and 422 (see the
    // next test). Declaring 1,000 of odd change ("tiền lẻ") brings counted
    // cash back to 105,000 → zero variance → settles.
    $response = actingAsCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 4], // 104,000
            ],
            'closing_cash_adjustment' => 1000,
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
            ],
        ],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');
    expect((float) $response['counted_cash_amount'])->toBe(105000.0);
    expect((float) $response['closing_cash_adjustment_amount'])->toBe(1000.0);
    expect((float) $response['cash_variance_amount'])->toBe(0.0);
});

it('rejects close with cash variance > tolerance and no reason (422)', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    // Count 1000 short.
    $response = actingAsCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 4], // -1000
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
            ],
        ],
    )->assertStatus(422);
    expect($response->json('code'))->toBe('VARIANCE_REASON_REQUIRED');
});

it('accepts close with cash variance + reason in closing_note', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    $response = actingAsCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 4],
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0, 'variance_reason' => 'Lost coin'],
                ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
            ],
            'closing_note' => 'See cash reason',
        ],
    )->assertOk()->json('data');
    expect((float) $response['cash_variance_amount'])->toBe(-1000.0);
});

// =========================================================================
//  Abandon
// =========================================================================

it('abandons an open shift with no payments and clears the till', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    $response = actingAsCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/abandon",
        ['abandon_reason' => 'Mis-open'],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('abandoned');
    $till = Till::where('branch_id', $this->shop->id)->firstOrFail();
    expect($till->current_session_id)->toBeNull();
});

it('refuses abandon when a payment is already stamped (409 SHIFT_HAS_PAYMENTS)', function () {
    $session = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->json('data');

    // Create an OrderPayment directly stamped to the session (skip the
    // middleware path; this test focuses on the abandon guard).
    $payMethod = PaymentMethod::firstOrCreate(
        ['organization_id' => $this->orgId, 'branch_id' => null, 'code' => 'cash'],
        [
            'name' => 'Cash',
            'is_auto_confirm' => true,
            'requires_tendered' => true,
            'is_active' => true,
            'sort_order' => 0,
        ],
    );
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->shop->id,
    ]);
    OrderPayment::create([
        'payment_code' => 'PAY-2026-'.fake()->unique()->numberBetween(1000, 9999),
        'amount' => 1000,
        'status' => 'succeeded',
        'paid_at' => now(),
        'customer_order_id' => $order->id,
        'payment_method_id' => $payMethod->id,
        'branch_id' => $this->shop->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
        'till_session_id' => $session['id'],
    ]);

    $response = actingAsCashier()
        ->postJson("/api/v1/pos/till/sessions/{$session['id']}/abandon")
        ->assertStatus(409);
    expect($response->json('code'))->toBe('SHIFT_HAS_PAYMENTS');
});

// =========================================================================
//  Middleware — ResolveOpenTillSession
// =========================================================================

it('blocks POST /pos/orders with 409 NO_OPEN_SHIFT when no shift is open', function () {
    $response = actingAsCashier()->postJson('/api/v1/pos/orders', [
        'guest_count' => 1,
    ])->assertStatus(409);
    expect($response->json('code'))->toBe('NO_OPEN_SHIFT');
});

it('stamps prices_include_tax_at_open from the branch setting on shift open (plan-043 T1.12)', function () {
    ShopOrderSetting::updateOrCreate(
        ['branch_id' => $this->shop->id],
        // JPY so the shift currency matches the JPY opening denominations (else
        // the drawer-currency guard 422s DENOMINATION_CURRENCY_MISMATCH).
        ['prices_include_tax' => true, 'organization_id' => $this->orgId, 'currency_code' => 'JPY'],
    );

    $response = actingAsCashier()
        ->postJson('/api/v1/pos/till/sessions', openShiftPayload())
        ->assertStatus(201);

    $session = TillSession::find($response->json('data.id'));
    expect((bool) $session->prices_include_tax_at_open)->toBeTrue();
});
