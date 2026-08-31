<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\TillCashEvent;
use App\Models\TillSession;
use App\Models\TillTenderType;
use App\Models\User;
use App\Services\Shop\ShopTillTrackingService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * RE-VERIFY on `dev`:
 *   B3a — Z-report revenue = SUM(customer_orders.total_amount) of distinct
 *         orders touched by the session → one bill split across two shifts is
 *         booked in FULL in BOTH.
 *   B3b — a debt (on_account) bill inflates shift revenue and has no
 *         `on_account` bucket in category_expected.
 *   B5  — the Z-report PDF prints only 4 of the 7 terms of expected_cash.
 *   B6  — closing_cash_adjustment has no cap / reason / denomination
 *         cross-check → a 5,000-short cashier can zero out the variance.
 *   B7  — TillCashEvent has no currency guard → a JPY shift accepts a
 *         paid_in of 100 USD and expected_cash rises by 100 (yen).
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
        'slug' => 'vtl-revenue-shop',
        'is_active' => true,
    ]);

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->cashier, $this->orgId);

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();

    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    // The seeded `on_account` method: code = 'debt', type = 'on_account'.
    $this->debtMethod = PaymentMethod::factory()->create([
        'code' => 'debt',
        'type' => 'on_account',
        'is_auto_confirm' => true,
        'is_active' => true,
        'organization_id' => $this->orgId,
    ]);
});

function vtlrActor(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

function vtlrOpenShift(int $tenThousands = 10): array
{
    return vtlrActor()
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [
                ['denomination_id' => test()->jpy10000->id, 'quantity' => $tenThousands],
            ],
            'opened_by_id' => (string) Str::uuid(),
        ])
        ->assertCreated()
        ->json('data');
}

/**
 * An order with a real Customer attached — required for any PARTIAL payment on
 * the POS namespace (the walk-in full-payment guard blocks partials on
 * customer-less orders). A named diner is the normal case for a split bill or
 * an on-account (debt) sale anyway.
 */
function vtlrOrder(float $total, bool $withCustomer = false): CustomerOrder
{
    $customerId = null;
    if ($withCustomer) {
        $customerId = Customer::factory()->create([
            'branch_id' => test()->shop->id,
            'brand_id' => test()->brand->id,
            'organization_id' => test()->orgId,
        ])->id;
    }

    return CustomerOrder::create([
        'order_code' => 'ORD-'.Str::upper(Str::random(8)),
        'order_type' => 'dine_in',
        'status' => 'paying',
        'customer_id' => $customerId,
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

function vtlrPay(CustomerOrder $order, PaymentMethod $method, float $amount): array
{
    $payload = [
        'payment_method_id' => $method->id,
        'amount' => $amount,
    ];
    if ($method->code === 'cash') {
        $payload['tendered_amount'] = $amount;
    }

    return vtlrActor()
        ->postJson("/api/v1/pos/orders/{$order->id}/payments", $payload)
        ->assertCreated()
        ->json('data');
}

function vtlrClose(string $sessionId, array $overrides = []): TestResponse
{
    return vtlrActor()->postJson("/api/v1/pos/till/sessions/{$sessionId}/close", array_merge([
        'closing_counts' => [['denomination_id' => test()->jpy10000->id, 'quantity' => 10]],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
        ],
    ], $overrides));
}

function vtlrReconcile(string $sessionId): array
{
    return vtlrActor()
        ->getJson("/api/v1/pos/till/sessions/{$sessionId}/reconciliation")
        ->assertOk()
        ->json('data');
}

// =========================================================================
//  B3a — one bill split across two shifts is booked in FULL in BOTH
// =========================================================================

it('B3a: a 10,000 bill half-paid in shift 1 and half in shift 2 books 10,000 of revenue in BOTH (20,000 total)', function () {
    $s1 = vtlrOpenShift();
    $order = vtlrOrder(10000, withCustomer: true);

    vtlrPay($order, $this->cashMethod, 5000); // half in shift 1

    // Shift 1 closes: float 100,000 + 5,000 cash = 105,000.
    vtlrClose($s1['id'], [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
        ],
    ])->assertOk();

    $s2 = vtlrOpenShift();
    vtlrPay($order, $this->cashMethod, 5000); // other half in shift 2

    $r1 = vtlrReconcile($s1['id']);
    $r2 = vtlrReconcile($s2['id']);

    dump([
        'shift1_cash_sales' => (float) $r1['cash']['cash_sales'],
        'shift1_revenue_gross' => (float) $r1['revenue']['gross'],
        'shift2_cash_sales' => (float) $r2['cash']['cash_sales'],
        'shift2_revenue_gross' => (float) $r2['revenue']['gross'],
        'sum_of_both_shifts_revenue' => (float) $r1['revenue']['gross'] + (float) $r2['revenue']['gross'],
        'money_actually_taken' => 10000.0,
    ]);

    // Cash is attributed correctly (5,000 each).
    expect((float) $r1['cash']['cash_sales'])->toBe(5000.0);
    expect((float) $r2['cash']['cash_sales'])->toBe(5000.0);

    // But revenue books the WHOLE bill in EACH shift → 20,000 for a 10,000 bill.
    expect((float) $r1['revenue']['gross'])->toBe(10000.0);
    expect((float) $r2['revenue']['gross'])->toBe(10000.0);
});

// =========================================================================
//  B3b — an on_account (debt) bill inflates revenue; no on_account bucket
// =========================================================================

it('B3b: cash 3,000 + debt 7,000 → expected_cash +3,000 but revenue gross 10,000, and there is no on_account bucket', function () {
    $s1 = vtlrOpenShift();
    $order = vtlrOrder(10000, withCustomer: true);

    vtlrPay($order, $this->cashMethod, 3000);
    vtlrPay($order, $this->debtMethod, 7000); // customer owes 7,000

    $recon = vtlrReconcile($s1['id']);

    dump([
        'cash_sales' => (float) $recon['cash']['cash_sales'],
        'expected_cash' => (float) $recon['cash']['expected_cash'],
        'revenue_gross' => (float) $recon['revenue']['gross'],
        'category_expected' => $recon['category_expected'],
    ]);

    expect((float) $recon['cash']['cash_sales'])->toBe(3000.0);
    expect((float) $recon['cash']['expected_cash'])->toBe(103000.0);

    // Revenue books the full bill, including the 7,000 nobody has paid yet.
    expect((float) $recon['revenue']['gross'])->toBe(10000.0);

    // …and there is nowhere to declare/reconcile the debt at close.
    expect($recon['category_expected'])->not->toHaveKey('on_account');
    expect(array_keys($recon['category_expected']))->toBe(['cash', 'card', 'qr', 'emoney']);
});

// =========================================================================
//  B5 — Z-report PDF payload prints only 4 of 7 expected_cash terms
// =========================================================================

it('B5: the Z-report cash_summary omits cash_tips, loan_from_safe and pickup_to_safe → printed terms do not sum to the printed expected', function () {
    $s1 = vtlrOpenShift(); // float 100,000

    // A cash sale WITH a cash tip — the tip physically stays in the drawer.
    $order = vtlrOrder(10000);
    vtlrActor()->postJson("/api/v1/pos/orders/{$order->id}/payments", [
        'payment_method_id' => $this->cashMethod->id,
        'amount' => 10000,
        'tip_amount' => 500,
        'tendered_amount' => 11000,
    ])->assertCreated();

    // Every one of the four cash-event types.
    foreach ([
        ['paid_in', 2000],
        ['paid_out', 1000],
        ['loan_from_safe', 3000],
        ['pickup_to_safe', 4000],
    ] as [$type, $amount]) {
        vtlrActor()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/cash-events", [
            'event_type' => $type,
            'amount' => $amount,
            'reason' => "vtl {$type}",
        ])->assertCreated();
    }

    $recon = vtlrReconcile($s1['id']);

    // Close so the Z-report is renderable, using the true expected as counted.
    $expected = (float) $recon['cash']['expected_cash'];
    vtlrClose($s1['id'], [
        'closing_counts' => [['denomination_id' => $this->jpy10000->id, 'quantity' => 10]],
        'closing_cash_adjustment' => $expected - 100000,
    ])->assertOk();

    $payload = app(ShopTillTrackingService::class)
        ->buildZReportPayload(TillSession::find($s1['id']));

    $cs = $payload['cash_summary'];

    $printedTerms = (float) $cs['opening_float']
        + (float) $cs['cash_sales']
        + (float) $cs['paid_in']
        - (float) $cs['paid_out'];

    dump([
        'reconcile_expected_cash' => $expected,
        'reconcile_all_7_terms' => $recon['cash'],
        'zreport_cash_summary_keys' => array_keys($cs),
        'zreport_printed_expected' => (float) $cs['expected'],
        'sum_of_printed_terms' => $printedTerms,
        'unexplained_gap' => (float) $cs['expected'] - $printedTerms,
    ]);

    // The three missing terms.
    expect($cs)->not->toHaveKey('cash_tips');
    expect($cs)->not->toHaveKey('loan_from_safe');
    expect($cs)->not->toHaveKey('pickup_to_safe');

    // Printed expected = 100,000 + 10,000 + 500 + 2,000 + 3,000 − 1,000 − 4,000 = 110,500
    expect((float) $cs['expected'])->toBe(110500.0);
    // Printed terms only sum to 100,000 + 10,000 + 2,000 − 1,000 = 111,000
    expect($printedTerms)->toBe(111000.0);
    // …so the PDF's own arithmetic does not close, by 500 − 3,000 + 4,000.
    expect((float) $cs['expected'] - $printedTerms)->toBe(-500.0);
});

// =========================================================================
//  B6 — closing_cash_adjustment has no cap / reason / cross-check
// =========================================================================

it('B6: a 5,000-short cashier declares closing_cash_adjustment = 5000, variance goes to 0 and VARIANCE_REASON_REQUIRED never fires', function () {
    $s1 = vtlrOpenShift(); // float 100,000
    $order = vtlrOrder(20000);
    vtlrPay($order, $this->cashMethod, 20000); // expected = 120,000

    // Honest close: the drawer really holds 115,000 (cashier is 5,000 short).
    $honest = vtlrClose($s1['id'], [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 11],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
        ],
    ]);
    dump(['honest_close_status' => $honest->status(), 'honest_code' => $honest->json('code')]);
    $honest->assertStatus(422)->assertJsonPath('code', 'VARIANCE_REASON_REQUIRED');

    // Dishonest close: same physical 115,000, but declare a 5,000 "adjustment".
    $fudged = vtlrClose($s1['id'], [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 11],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
        ],
        'closing_cash_adjustment' => 5000,
    ]);

    $session = TillSession::find($s1['id']);

    dump([
        'fudged_close_status' => $fudged->status(),
        'expected_cash_amount' => (float) $session->expected_cash_amount,
        'counted_cash_amount' => (float) $session->counted_cash_amount,
        'closing_cash_adjustment_amount' => (float) $session->closing_cash_adjustment_amount,
        'cash_variance_amount' => (float) $session->cash_variance_amount,
        'closing_note' => $session->closing_note,
    ]);

    $fudged->assertOk();
    expect($session->status->value)->toBe('settled');
    expect((float) $session->cash_variance_amount)->toBe(0.0);      // shortfall erased
    expect((float) $session->counted_cash_amount)->toBe(120000.0);  // drawer really holds 115,000
    expect((float) $session->closing_cash_adjustment_amount)->toBe(5000.0);
    expect($session->closing_note)->toBeNull();                     // no reason ever required
});

// =========================================================================
//  B7 — no currency guard on TillCashEvent
// =========================================================================

it('B7: a JPY shift accepts a paid_in of 100 USD and expected_cash rises by 100 yen', function () {
    $s1 = vtlrOpenShift(); // JPY, float 100,000
    expect(TillSession::find($s1['id'])->default_currency_code)->toBe('JPY');

    $resp = vtlrActor()->postJson("/api/v1/pos/till/sessions/{$s1['id']}/cash-events", [
        'event_type' => 'paid_in',
        'amount' => 100,
        'currency_code' => 'USD',
        'reason' => 'foreign note dropped in the drawer',
    ]);

    $event = TillCashEvent::where('session_id', $s1['id'])->first();
    $recon = vtlrReconcile($s1['id']);

    dump([
        'cash_event_http_status' => $resp->status(),
        'stored_currency_code' => $event?->currency_code,
        'stored_amount' => (float) ($event?->amount ?? 0),
        'shift_currency' => 'JPY',
        'paid_in_in_reconcile' => (float) $recon['cash']['paid_in'],
        'expected_cash' => (float) $recon['cash']['expected_cash'],
    ]);

    $resp->assertCreated();
    expect($event->currency_code)->toBe('USD');
    // 100 USD was added to a yen drawer as if it were 100 yen.
    expect((float) $recon['cash']['paid_in'])->toBe(100.0);
    expect((float) $recon['cash']['expected_cash'])->toBe(100100.0);
});
