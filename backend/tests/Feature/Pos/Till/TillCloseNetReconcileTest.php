<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Denomination;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\Role;
use App\Models\TillSettlementTenderDetail;
use App\Models\TillTenderType;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Plan 030 — Net (売上 − 取消) settlement math + reconciliation identity driven
 * through the REAL service (test-gap audit, findings 1 + 3 + 4).
 *
 *   (finding 1) MultiTenderReconcileTest declares every terminal tender with
 *   cancel_amount = 0, so the net = gross − cancel path with a NON-ZERO 取消
 *   (the Stera void column — the whole reason DEC-4 captures gross/cancel
 *   separately) was never exercised end-to-end. Here a 13,000 売上 / 1,000 取消
 *   credit slip nets to 12,000, reconciles flat against a 12,000 card expected,
 *   and every persisted column (gross, cancel, net, terminal_batch_total,
 *   variance) is asserted.
 *
 *   (finding 4) TillSessionMathTest re-implements `opening + sales + in − out`
 *   inline in the test body (tautological — it asserts round(a) === round(a)).
 *   The second case here pins the SAME identity against the actual
 *   TillSessionService::reconcile() + close() output over the phone, with cash
 *   sales AND a paid_in AND a paid_out all live at once.
 *
 * Money math is EXACT integer yen — no tolerance.
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
        'slug' => 'net-reconcile-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-staff'],
        ['name' => 'Org Staff', 'level' => 10],
    );

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashier, $this->orgId);

    $this->jpy10000 = Denomination::factory()->jpy10000()->create();
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();

    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);

    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    $this->cardMethod = PaymentMethod::factory()->card()->create(['organization_id' => $this->orgId]);
});

function actingAsNetCashier(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

/** Open a shift with a 105,000 float. */
function openNetShift(): array
{
    return actingAsNetCashier()
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [
                ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
            ],
            'opened_by_id' => (string) Str::uuid(),
        ])
        ->assertCreated()
        ->json('data');
}

/** Stamp a succeeded sale (order + payment) of $method/$amount to $sessionId. */
function stampNetSale(object $ctx, string $sessionId, PaymentMethod $method, float $amount): OrderPayment
{
    $order = CustomerOrder::create([
        'order_code' => 'ORD-'.date('Y').'-'.random_int(100000, 999999),
        'order_type' => 'dine_in',
        'status' => 'closed',
        'subtotal' => $amount,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => $amount,
        'paid_amount' => $amount,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $ctx->shop->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
    ]);

    return OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $method->id,
        'amount' => $amount,
        'status' => 'succeeded',
        'refund_of_id' => null,
        'till_session_id' => $sessionId,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $ctx->orgId,
        'branch_id' => $ctx->shop->id,
        'brand_id' => $ctx->brand->id,
    ]);
}

it('nets a credit slip gross − cancel and reconciles flat against POS expected', function () {
    $session = openNetShift();

    // POS recorded a 12,000 card sale → credit anchor expected = 12,000.
    stampNetSale($this, $session['id'], $this->cardMethod, 12000);

    // The printed Stera slip shows 13,000 売上 and 1,000 取消 (a void) → net 12,000.
    $response = actingAsNetCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5], // 105,000 = float → cash variance 0
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                [
                    'tender_key' => 'credit',
                    'gross_amount' => 13000,
                    'cancel_amount' => 1000,
                    'terminal_batch_total' => 13000,
                ],
            ],
        ],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');
    expect((float) $response['cash_variance_amount'])->toBe(0.0);

    $credit = TillSettlementTenderDetail::where('session_id', $session['id'])
        ->where('tender_key', 'credit')
        ->first();

    // Both raw columns are retained for void auditing, net is derived, variance
    // is computed on the NET (not the gross) — so a fully-offset void balances.
    expect((float) $credit->declared_gross_amount)->toBe(13000.0);
    expect((float) $credit->declared_cancel_amount)->toBe(1000.0);
    expect((float) $credit->declared_amount)->toBe(12000.0);
    expect((float) $credit->expected_amount)->toBe(12000.0);
    expect((float) $credit->variance_amount)->toBe(0.0);
    expect((float) $credit->terminal_batch_total)->toBe(13000.0);
});

it('produces a signed net variance when the void does not fully offset the gross', function () {
    $session = openNetShift();

    stampNetSale($this, $session['id'], $this->cardMethod, 12000); // expected 12,000

    // 13,000 売上 − 500 取消 = 12,500 net → +500 over vs 12,000 expected → reason required.
    $payload = fn (?string $reason) => [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5],
        ],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            ['tender_key' => 'credit', 'gross_amount' => 13000, 'cancel_amount' => 500, 'variance_reason' => $reason],
        ],
    ];

    actingAsNetCashier()
        ->postJson("/api/v1/pos/till/sessions/{$session['id']}/close", $payload(null))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VARIANCE_REASON_REQUIRED');

    actingAsNetCashier()
        ->postJson("/api/v1/pos/till/sessions/{$session['id']}/close", $payload('Slip net over by 500'))
        ->assertOk();

    $credit = TillSettlementTenderDetail::where('session_id', $session['id'])
        ->where('tender_key', 'credit')
        ->first();
    expect((float) $credit->declared_amount)->toBe(12500.0);
    expect((float) $credit->variance_amount)->toBe(500.0);
});

it('reconcile() computes expected_cash = opening + cash_sales + paid_in − paid_out through the real service', function () {
    $session = openNetShift(); // float 105,000

    // Live cash sale + a paid_in AND a paid_out on the same shift.
    stampNetSale($this, $session['id'], $this->cashMethod, 8000);

    actingAsNetCashier()->postJson("/api/v1/pos/till/sessions/{$session['id']}/cash-events", [
        'event_type' => 'paid_in', 'amount' => 2000, 'reason' => 'float top-up',
    ])->assertCreated();
    actingAsNetCashier()->postJson("/api/v1/pos/till/sessions/{$session['id']}/cash-events", [
        'event_type' => 'paid_out', 'amount' => 3000, 'reason' => 'supplier cash',
    ])->assertCreated();

    // expected_cash = 105,000 + 8,000 + 2,000 − 3,000 = 112,000 — asserted
    // against the REAL reconcile() output (not an inline re-computation).
    $recon = actingAsNetCashier()
        ->getJson("/api/v1/pos/till/sessions/{$session['id']}/reconciliation")
        ->assertOk()
        ->json('data');

    expect((float) $recon['cash']['opening_float'])->toBe(105000.0);
    expect((float) $recon['cash']['cash_sales'])->toBe(8000.0);
    expect((float) $recon['cash']['paid_in'])->toBe(2000.0);
    expect((float) $recon['cash']['paid_out'])->toBe(3000.0);
    expect((float) $recon['cash']['expected_cash'])->toBe(112000.0);

    // Counting exactly 112,000 → zero variance → settles without a reason.
    $closed = actingAsNetCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 11], // 110,000
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 2],   //   2,000 → 112,000
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
            ],
        ],
    )->assertOk()->json('data');

    expect((float) $closed['expected_cash_amount'])->toBe(112000.0);
    expect((float) $closed['counted_cash_amount'])->toBe(112000.0);
    expect((float) $closed['cash_variance_amount'])->toBe(0.0);
});
