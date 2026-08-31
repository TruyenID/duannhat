<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\TillSession;
use App\Models\TillTenderType;
use App\Models\User;
use App\Services\Pos\TillSessionService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * RE-VERIFY on `dev`:
 *   #552 — confirming a non-cash payment AFTER its shift settled must not
 *          re-stamp revenue onto the settled shift.
 *          Sub-claim: with NO shift open at confirm time, does the payment KEEP
 *          the old (settled) shift's stamp?
 *   B1   — POST /shops/{slug}/orders/{o}/payments has no ResolveOpenTillSession
 *          → does a cash payment through the SHOP namespace get
 *          till_session_id = NULL and miss every shift's expected_cash?
 *   B4   — carry-over gap payments double-count against the hand-counted
 *          opening float.
 */

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->shop = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'slug' => 'vtl-confirm-shop',
        'is_active' => true,
    ]);

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->cashier, $this->orgId);

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();

    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    $this->cardMethod = PaymentMethod::factory()->card()->create(['organization_id' => $this->orgId]); // is_auto_confirm=false → pending
});

function vtlcActor(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

function vtlcOpenShift(int $tenThousands = 10): array
{
    return vtlcActor()
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [
                ['denomination_id' => test()->jpy10000->id, 'quantity' => $tenThousands],
            ],
            'opened_by_id' => (string) Str::uuid(),
        ])
        ->assertCreated()
        ->json('data');
}

function vtlcOrder(float $total): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-'.Str::upper(Str::random(8)),
        'order_type' => 'dine_in',
        'status' => 'paying',
        'subtotal' => $total,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->shop->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

function vtlcCloseShift(string $sessionId, int $countedTenThousands, ?string $note = null): TestResponse
{
    return vtlcActor()->postJson("/api/v1/pos/till/sessions/{$sessionId}/close", array_filter([
        'closing_counts' => [['denomination_id' => test()->jpy10000->id, 'quantity' => $countedTenThousands]],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
        ],
        'closing_note' => $note,
    ]));
}

function vtlcReconcile(string $sessionId): array
{
    return vtlcActor()
        ->getJson("/api/v1/pos/till/sessions/{$sessionId}/reconciliation")
        ->assertOk()
        ->json('data');
}

// =========================================================================
//  #552 — confirm after settle
// =========================================================================

it('#552: a shift with a pending card payment cannot be closed — the re-stamp hazard is prevented at source', function () {
    $s1 = vtlcOpenShift();
    $order = vtlcOrder(10000);

    // Card payment created in shift 1 → pending (is_auto_confirm = false).
    $payment = vtlcActor()
        ->postJson("/api/v1/pos/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cardMethod->id,
            'amount' => 10000,
        ])
        ->assertCreated()
        ->json('data');

    expect(OrderPayment::find($payment['id'])->till_session_id)->toBe($s1['id']);

    // RESOLVED (#821 #552): the shift refuses to close while money is still in
    // flight. That removes the whole hazard this finding documented — a payment
    // can no longer be confirmed AFTER its shift settled, so it can never
    // re-stamp onto a different shift or retro-mutate a closed Z-report.
    $close = vtlcCloseShift($s1['id'], 10);
    $close->assertStatus(409);
    expect($close->json('code'))->toBe('PENDING_PAYMENTS_BLOCK_CLOSE');
    expect($close->json('pending_count'))->toBe(1);

    // The shift is still open and the payment still belongs to it.
    expect(TillSession::find($s1['id'])->status->value)->toBe('open');
    expect(OrderPayment::find($payment['id'])->till_session_id)->toBe($s1['id']);
});

it('#552 sub-claim: the same guard blocks the close even when no other shift would be open', function () {
    $s1 = vtlcOpenShift();
    $order = vtlcOrder(10000);

    $payment = vtlcActor()
        ->postJson("/api/v1/pos/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cardMethod->id,
            'amount' => 10000,
        ])
        ->assertCreated()
        ->json('data');

    // RESOLVED (#821 #552): without an open shift to receive it, a confirm after
    // settle used to retro-grow the SETTLED shift's Z-report. The close guard
    // makes that unreachable — the shift simply cannot settle yet.
    $close = vtlcCloseShift($s1['id'], 10);
    $close->assertStatus(409);
    expect($close->json('code'))->toBe('PENDING_PAYMENTS_BLOCK_CLOSE');
    expect(TillSession::find($s1['id'])->status->value)->toBe('open');
});

it('B1: a cash payment through the SHOP namespace gets till_session_id = NULL even with a shift open', function () {
    $s1 = vtlcOpenShift();
    $order = vtlcOrder(7000);

    // Same money, but through /shops/{slug}/... instead of /pos/...
    $payment = vtlcActor()
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 7000,
            'tendered_amount' => 7000,
        ])
        ->assertCreated()
        ->json('data');

    $row = OrderPayment::find($payment['id']);
    $recon = vtlcReconcile($s1['id']);

    dump([
        'open_shift_id' => $s1['id'],
        'payment_till_session_id' => $row->till_session_id,
        'shift_cash_sales' => (float) $recon['cash']['cash_sales'],
        'shift_expected_cash' => (float) $recon['cash']['expected_cash'],
    ]);

    // 7,000 yen of real cash is in the drawer but no shift knows about it.
    expect($row->till_session_id)->toBeNull();
    expect((float) $recon['cash']['cash_sales'])->toBe(0.0);
    expect((float) $recon['cash']['expected_cash'])->toBe(100000.0);
});

it('B1 consequence: the orphaned shop-namespace cash makes the OPEN shift close SHORT by exactly that amount', function () {
    $s1 = vtlcOpenShift();
    $order = vtlcOrder(7000);

    vtlcActor()->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
        'payment_method_id' => $this->cashMethod->id,
        'amount' => 7000,
        'tendered_amount' => 7000,
    ])->assertCreated();

    // Drawer physically holds 100,000 float + 7,000 = 107,000. The cashier
    // counts it honestly. (10x10,000 + we approximate the 7,000 with the
    // closing_cash_adjustment field, which is exactly what a cashier would do.)
    $close = vtlcActor()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/close", [
        'closing_counts' => [['denomination_id' => $this->jpy10000->id, 'quantity' => 10]],
        'closing_cash_adjustment' => 7000,
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
        ],
    ]);

    dump(['close_status' => $close->status(), 'code' => $close->json('code')]);

    // Honest count → phantom +7,000 OVER variance, close blocked.
    $close->assertStatus(422)->assertJsonPath('code', 'VARIANCE_REASON_REQUIRED');
});

// =========================================================================
//  B4 — carry-over gap payment double-counts against the counted float
// =========================================================================

it('B4: a gap cash payment is NOT auto-adopted, so it cannot double-count against the counted float', function () {
    $s1 = vtlcOpenShift();
    vtlcCloseShift($s1['id'], 10)->assertOk(); // settled, drawer = 100,000

    // GAP: a cash payment lands with no open shift (e.g. the shop-namespace
    // route from B1, or a workstation replay). It has till_session_id = NULL.
    $order = vtlcOrder(5000);
    $gapPayment = vtlcActor()
        ->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cashMethod->id,
            'amount' => 5000,
            'tendered_amount' => 5000,
        ])
        ->assertCreated()
        ->json('data');

    expect(OrderPayment::find($gapPayment['id'])->till_session_id)->toBeNull();

    // The next cashier PHYSICALLY COUNTS the drawer at open. The 5,000 gap cash
    // is already sitting in it → the honest count is 105,000, but the cashier
    // (per plan-030 UX) counts denominations: 10 x 10,000 + 5,000 note. To make
    // the double-count unambiguous we count ONLY the 10 x 10,000 the float
    // actually is — i.e. the gap cash is NOT part of the declared float.
    $s2 = vtlcOpenShift(10); // opening_float = 100,000

    $row = OrderPayment::find($gapPayment['id'])->refresh();
    $recon = vtlcReconcile($s2['id']);
    $session2 = TillSession::find($s2['id']);

    dump([
        'gap_payment_stamp_after_open' => $row->till_session_id,
        'shift2_id' => $s2['id'],
        'shift2_opening_float' => (float) $session2->opening_float_amount,
        'shift2_cash_sales' => (float) $recon['cash']['cash_sales'],
        'shift2_expected_cash' => (float) $recon['cash']['expected_cash'],
    ]);

    // RESOLVED (#821 B4): plan-044 R2 removed the automatic carry-over. A gap
    // payment stays unattributed until the cashier explicitly claims it at open
    // (claimed_gap_payment_ids), so it can no longer be silently adopted AFTER
    // the float was counted and double-count against the drawer.
    expect($row->till_session_id)->toBeNull();
    expect((float) $session2->opening_float_amount)->toBe(100000.0);
    // expected = the counted float only; the unclaimed gap cash does not inflate it.
    expect((float) $recon['cash']['expected_cash'])->toBe(100000.0);
});

it('B4: an unclaimed gap payment never reaches the new shift through the real service', function () {
    $svc = app(TillSessionService::class);

    $s1 = vtlcOpenShift();
    vtlcCloseShift($s1['id'], 10)->assertOk();

    $order = vtlcOrder(5000);
    vtlcActor()->postJson("/api/v1/shops/{$this->shop->slug}/orders/{$order->id}/payments", [
        'payment_method_id' => $this->cashMethod->id,
        'amount' => 5000,
        'tendered_amount' => 5000,
    ])->assertCreated();

    // No open shift → resolver returns null (so nothing stamps the gap cash).
    expect($svc->openSessionIdForBranch($this->shop->id))->toBeNull();

    $s2 = vtlcOpenShift(10);
    expect($svc->openSessionIdForBranch($this->shop->id))->toBe($s2['id']);

    // RESOLVED (#821 B4): with no auto-adoption, the unclaimed gap cash stays out
    // of shift 2's figures entirely.
    $recon = vtlcReconcile($s2['id']);
    expect((float) $recon['cash']['cash_sales'])->toBe(0.0);
});
