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
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * RE-VERIFY on `dev`:
 *   #523 — a cash REFUND must reduce expected_cash of the shift it happens in.
 *   B3c  — sell 20,000 then refund 20,000 in the SAME shift: cash nets to 0,
 *          what happens to revenue gross?
 *   B2   — the pos REFUND route carries no ResolveOpenTillSession middleware.
 *          Where does the refund row land when NO shift is open?
 *
 * Everything is driven through REAL HTTP routes with real auth.
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
        'slug' => 'vtl-refund-shop',
        'is_active' => true,
    ]);

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->cashier, $this->orgId); // org-admin → may refund

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();

    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
});

function vtlActor(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

/** Open a shift through the real POS route. Float = 10 x 10,000 = 100,000. */
function vtlOpenShift(int $tenThousands = 10): array
{
    return vtlActor()
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [
                ['denomination_id' => test()->jpy10000->id, 'quantity' => $tenThousands],
            ],
            'opened_by_id' => (string) Str::uuid(),
        ])
        ->assertCreated()
        ->json('data');
}

/** A real order sitting in `paying`, ready to accept a payment. */
function vtlOrder(float $total): CustomerOrder
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

/** POST /pos/orders/{o}/payments — the real POS cash-payment path. */
function vtlPayCash(CustomerOrder $order, float $amount): array
{
    return vtlActor()
        ->postJson("/api/v1/pos/orders/{$order->id}/payments", [
            'payment_method_id' => test()->cashMethod->id,
            'amount' => $amount,
            'tendered_amount' => $amount,
        ])
        ->assertCreated()
        ->json('data');
}

/** POST /pos/orders/{o}/payments/{p}/refund — the real POS refund path. */
function vtlRefund(CustomerOrder $order, string $paymentId, ?float $amount = null): array
{
    return vtlActor()
        ->postJson(
            "/api/v1/pos/orders/{$order->id}/payments/{$paymentId}/refund",
            $amount === null ? [] : ['amount' => $amount],
        )
        ->assertSuccessful()
        ->json('data');
}

function vtlReconcile(string $sessionId): array
{
    return vtlActor()
        ->getJson("/api/v1/pos/till/sessions/{$sessionId}/reconciliation")
        ->assertOk()
        ->json('data');
}

// =========================================================================
//  #523 — cash refund must reduce expected_cash
// =========================================================================

it('#523: a cash refund reduces expected_cash of the shift it happens in', function () {
    $session = vtlOpenShift(); // float 100,000

    $order = vtlOrder(20000);
    $payment = vtlPayCash($order, 20000);

    $before = vtlReconcile($session['id']);
    expect((float) $before['cash']['expected_cash'])->toBe(120000.0);

    vtlRefund($order, $payment['id'], 20000);

    $after = vtlReconcile($session['id']);

    dump([
        'expected_cash_before_refund' => (float) $before['cash']['expected_cash'],
        'expected_cash_after_refund' => (float) $after['cash']['expected_cash'],
        'cash_sales_after' => (float) $after['cash']['cash_sales'],
        'revenue_gross_after' => (float) $after['revenue']['gross'],
    ]);

    // The drawer physically holds only the 100,000 float again.
    expect((float) $after['cash']['expected_cash'])->toBe(100000.0);
    expect((float) $after['cash']['cash_sales'])->toBe(0.0);
});

it('#523: the refund row is stamped onto the open shift (not left NULL)', function () {
    $session = vtlOpenShift();
    $order = vtlOrder(5000);
    $payment = vtlPayCash($order, 5000);

    $refund = vtlRefund($order, $payment['id']);

    $refundRow = OrderPayment::find($refund['id']);
    dump([
        'refund_amount' => (float) $refundRow->amount,
        'refund_till_session_id' => $refundRow->till_session_id,
        'open_session_id' => $session['id'],
    ]);

    expect($refundRow->till_session_id)->toBe($session['id']);
    expect((float) $refundRow->amount)->toBe(-5000.0);
});

// =========================================================================
//  B3c — same-shift sell + refund: cash nets to 0, revenue gross?
// =========================================================================

it('B3c: sell 20,000 + refund 20,000 in the SAME shift — cash nets to 0, revenue gross is still counted', function () {
    $session = vtlOpenShift();

    $order = vtlOrder(20000);
    $payment = vtlPayCash($order, 20000);
    vtlRefund($order, $payment['id'], 20000);

    $recon = vtlReconcile($session['id']);

    dump([
        'cash_sales' => (float) $recon['cash']['cash_sales'],
        'expected_cash' => (float) $recon['cash']['expected_cash'],
        'revenue_gross' => (float) $recon['revenue']['gross'],
        'revenue_net' => (float) $recon['revenue']['net'],
    ]);

    // Cash side balances.
    expect((float) $recon['cash']['cash_sales'])->toBe(0.0);
    expect((float) $recon['cash']['expected_cash'])->toBe(100000.0);

    // Revenue side: the order is still recognized at its FULL total even though
    // every yen was handed back. Pin whatever dev actually does.
    expect((float) $recon['revenue']['gross'])->toBe(20000.0);
});

// =========================================================================
//  B2 — pos refund with NO shift open
// =========================================================================

it('B2: a refund with NO shift open stays unattributed — the next open() does not adopt it', function () {
    $s1 = vtlOpenShift(); // float 100,000

    $order = vtlOrder(20000);
    $payment = vtlPayCash($order, 20000); // cash into shift 1

    // Close shift 1 — counted = 100,000 float + 20,000 cash sale = 120,000.
    vtlActor()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/close", [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 12],
        ],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
        ],
    ])->assertOk();

    // NO shift open. Refund the 20,000.
    $refund = vtlRefund($order, $payment['id'], 20000);
    $refundRow = OrderPayment::find($refund['id']);

    $stampAtRefundTime = $refundRow->till_session_id;

    // Now the next cashier opens shift 2 with a physical 100,000 float.
    $s2 = vtlOpenShift(10);

    $refundRow->refresh();
    $recon2 = vtlReconcile($s2['id']);
    $settled1 = TillSession::find($s1['id']);

    dump([
        'refund_stamp_at_refund_time' => $stampAtRefundTime,
        'refund_stamp_after_next_open' => $refundRow->till_session_id,
        'shift1_id' => $s1['id'],
        'shift2_id' => $s2['id'],
        'shift1_settled_expected_cash' => (float) $settled1->expected_cash_amount,
        'shift2_expected_cash' => (float) $recon2['cash']['expected_cash'],
        'shift2_cash_sales' => (float) $recon2['cash']['cash_sales'],
    ]);

    // Document the real behaviour precisely.
    // RESOLVED (#821 B2): plan-044 R2 dropped the automatic carry-over, so a
    // refund taken in the close→open gap stays unattributed until a cashier
    // explicitly claims it. It is never silently pulled into the next shift.
    expect($stampAtRefundTime)->toBeNull();
    expect($refundRow->till_session_id)->toBeNull();

    // Shift 2's expected cash is its own counted float alone. The unclaimed gap
    // refund is not attributed to it, so it neither inflates nor deflates this
    // shift's figures — the outflow belongs to the gap, not to shift 2.
    expect((float) $recon2['cash']['expected_cash'])->toBe(100000.0);
});

it('B2 consequence: the gap refund is no longer double-counted against shift 2s hand-counted float', function () {
    $s1 = vtlOpenShift();
    $order = vtlOrder(20000);
    $payment = vtlPayCash($order, 20000);

    vtlActor()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/close", [
        'closing_counts' => [['denomination_id' => $this->jpy10000->id, 'quantity' => 12]],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
        ],
    ])->assertOk();

    // Gap: cash walks out of the drawer NOW, while no shift is open.
    vtlRefund($order, $payment['id'], 20000);

    // Cashier opens shift 2 and PHYSICALLY COUNTS the drawer. The 20,000 is
    // already gone, so the count is 100,000 — the float already reflects the
    // outflow.
    $s2 = vtlOpenShift(10);

    // Cashier closes shift 2 immediately: the drawer still holds the same
    // physical 100,000 they counted. Zero movement during shift 2.
    $close = vtlActor()->postJson("/api/v1/pos/till/sessions/{$s2['id']}/close", [
        'closing_counts' => [['denomination_id' => $this->jpy10000->id, 'quantity' => 10]],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
        ],
    ]);

    dump([
        'close_status' => $close->status(),
        'close_code' => $close->json('code'),
        'body' => $close->json(),
    ]);

    // RESOLVED (#821 B2): the gap refund is not adopted, so the cash outflow is
    // counted exactly once — physically, before the float count. Shift 2 closes
    // clean with no variance to explain.
    $close->assertOk();

    $recon = vtlReconcile($s2['id']);
    expect((float) $recon['cash']['expected_cash'])->toBe(100000.0); // matches the drawer exactly
});
