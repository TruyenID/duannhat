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
 * Plan 030 — Multi-tender payment-terminal (Stera 日計) reconciliation.
 *
 * Fills two gaps flagged by the plan-030 audit:
 *
 *   (Finding 1) Cash anchor tender was reconciled TWICE — once physically via
 *   the drawer count (counted_cash vs expected_cash) and again as a settlement
 *   tender (declared vs cashSales). Because the cashier declares cash on the
 *   drawer side (gross=0 on the terminal slip), a shift with real cash sales
 *   produced a PHANTOM per-tender variance of −cashSales that blocked close
 *   with VARIANCE_REASON_REQUIRED even when the drawer balanced perfectly.
 *
 *   (Finding 2) No test drove a full multi-tender close — cash + credit anchor
 *   + several QR sub-brands (category rollup) + e-money together. All prior
 *   close tests declared cash+credit with 0/0 and no real payments.
 *
 * Money math is EXACT (integer yen), no tolerance.
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
        'slug' => 'multi-tender-shop',
        'is_active' => true,
    ]);

    $role = Role::firstOrCreate(
        ['slug' => 'org-staff'],
        ['name' => 'Org Staff', 'level' => 10],
    );

    $this->cashier = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cashier->assignRole($role, $this->orgId);
    grantOrgAccess($this->cashier, $this->orgId);

    // Denominations for the drawer.
    $this->jpy10000 = Denomination::factory()->jpy10000()->create();
    $this->jpy1000 = Denomination::factory()->jpy1000()->create();

    // Tender masters: cash + credit anchors, two QR sub-brands (category
    // rollup), one e-money brand.
    TillTenderType::factory()->cash()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->credit()->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->qrBrand('paypay', 'PayPay')->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->qrBrand('rakuten_pay', 'Rakuten Pay')->create(['organization_id' => $this->orgId, 'branch_id' => null]);
    TillTenderType::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => null,
        'tender_key' => 'waon',
        'name' => 'WAON',
        'category' => 'emoney',
        'payment_method_code' => null,
        'is_expected_anchor' => false,
    ]);

    // POS payment methods behind the expected side.
    $this->cashMethod = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    $this->cardMethod = PaymentMethod::factory()->card()->create(['organization_id' => $this->orgId]);
    $this->cardTerminalMethod = PaymentMethod::factory()->cardTerminal()->create(['organization_id' => $this->orgId]);
    $this->eWalletMethod = PaymentMethod::factory()->eWallet()->create(['organization_id' => $this->orgId]);
    $this->transferMethod = PaymentMethod::factory()->transfer()->create(['organization_id' => $this->orgId]);
});

function actingAsMultiCashier(): TestCase
{
    /** @var TestCase $t */
    $t = test();

    return $t->actingAs($t->cashier)->withHeader('X-Shop-Slug', $t->shop->slug);
}

function openMultiShift(): array
{
    return actingAsMultiCashier()
        ->postJson('/api/v1/pos/till/sessions', [
            'opening_counts' => [
                ['denomination_id' => test()->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => test()->jpy1000->id, 'quantity' => 5],
            ],
            'opened_by_id' => (string) Str::uuid(),
        ])
        ->assertCreated()
        ->json('data'); // opening float = 105_000
}

/** Stamp a succeeded sale (order + payment) of $methodId/$amount to $sessionId. */
function stampSale(object $ctx, string $sessionId, PaymentMethod $method, float $amount): OrderPayment
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

// =========================================================================
//  Finding 1 — cash anchor must NOT double-reconcile
// =========================================================================

it('does not raise a phantom cash-tender variance when cash sales exist and cash is declared 0 on the terminal slip', function () {
    $session = openMultiShift();

    // 8,000 of real cash sales enter the drawer this shift.
    stampSale($this, $session['id'], $this->cashMethod, 8000);

    // Expected cash = 105,000 float + 8,000 cash sales = 113,000. Drawer counts
    // out to exactly that (10×10,000 + 3×1,000). Cash is declared 0 on the
    // terminal slip because cash never touches the payment terminal.
    $response = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 11], // 110,000
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 3],   //   3,000
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'credit', 'gross_amount' => 0, 'cancel_amount' => 0],
            ],
        ],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');
    expect((float) $response['expected_cash_amount'])->toBe(113000.0);
    expect((float) $response['counted_cash_amount'])->toBe(113000.0);
    expect((float) $response['cash_variance_amount'])->toBe(0.0);

    // The persisted cash settlement row carries NO terminal-expected / variance
    // (cash reconciles via the drawer, not the terminal).
    $cashDetail = TillSettlementTenderDetail::where('session_id', $session['id'])
        ->where('tender_key', 'cash')
        ->first();
    expect($cashDetail)->not->toBeNull();
    expect($cashDetail->expected_amount)->toBeNull();
    expect($cashDetail->variance_amount)->toBeNull();
});

// =========================================================================
//  Finding 2 — full multi-tender close (cash + credit + QR + e-money)
// =========================================================================

it('reconciles a full multi-tender shift: credit anchor per-row + QR category rollup + e-money, all balanced', function () {
    $session = openMultiShift();

    stampSale($this, $session['id'], $this->cashMethod, 8000);      // cash → drawer
    stampSale($this, $session['id'], $this->cardMethod, 12000);     // credit anchor expected
    stampSale($this, $session['id'], $this->eWalletMethod, 5000);   // emoney category (電子マネー)
    stampSale($this, $session['id'], $this->transferMethod, 3000);  // qr category (transfer rolls into qr)
    // qr category expected = 3,000 (transfer). emoney expected = 5,000 (e_wallet 電子マネー).

    $response = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 11], // 110,000
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 3],   //   3,000 → 113,000
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'credit', 'gross_amount' => 12000, 'cancel_amount' => 0],
                ['tender_key' => 'paypay', 'gross_amount' => 3000, 'cancel_amount' => 0],      // qr declared 3,000 == qr expected
                ['tender_key' => 'rakuten_pay', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'waon', 'gross_amount' => 5000, 'cancel_amount' => 0],        // emoney declared 5,000 == emoney expected
            ],
        ],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');
    expect((float) $response['cash_variance_amount'])->toBe(0.0);

    // Credit anchor: expected 12,000, declared 12,000, variance 0.
    $credit = TillSettlementTenderDetail::where('session_id', $session['id'])->where('tender_key', 'credit')->first();
    expect((float) $credit->expected_amount)->toBe(12000.0);
    expect((float) $credit->declared_amount)->toBe(12000.0);
    expect((float) $credit->variance_amount)->toBe(0.0);

    // QR sub-brands: no per-row expected (category rollup); declared recorded.
    $paypay = TillSettlementTenderDetail::where('session_id', $session['id'])->where('tender_key', 'paypay')->first();
    expect($paypay->expected_amount)->toBeNull();
    expect((float) $paypay->declared_amount)->toBe(3000.0);
    expect($paypay->variance_amount)->toBeNull();

    // E-money sub-brand: no per-row expected (category rollup); declared recorded.
    $waon = TillSettlementTenderDetail::where('session_id', $session['id'])->where('tender_key', 'waon')->first();
    expect($waon->expected_amount)->toBeNull();
    expect((float) $waon->declared_amount)->toBe(5000.0);
    expect($waon->variance_amount)->toBeNull();
});

it('balances the e-money category: e_wallet (電子マネー) expected reconciles against declared e-money brands', function () {
    // Regression for the plan-030 audit: category_expected['emoney'] was
    // hardcoded 0 while POS e_wallet (電子マネー) was summed into 'qr'. A shift
    // with real e-money sales could therefore NEVER balance — the declared
    // e-money net always diverged from the phantom 0 expected and demanded a
    // variance reason. Correct mapping: e_wallet → emoney, transfer → qr.
    $session = openMultiShift();

    stampSale($this, $session['id'], $this->eWalletMethod, 5000);   // emoney expected = 5,000

    // Cashier declares the matching 5,000 under the WAON e-money brand and
    // nothing else. Before the fix this produced a +5,000 emoney category
    // variance → 422 VARIANCE_REASON_REQUIRED. After the fix it balances.
    $response = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5], // 105,000 = float, cash variance 0
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'waon', 'gross_amount' => 5000, 'cancel_amount' => 0],
            ],
        ],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');

    $waon = TillSettlementTenderDetail::where('session_id', $session['id'])->where('tender_key', 'waon')->first();
    expect($waon->expected_amount)->toBeNull();      // sub-brand carries no per-row expected
    expect((float) $waon->declared_amount)->toBe(5000.0);
    expect($waon->variance_amount)->toBeNull();
});

it('requires a reason when the e-money category rollup diverges beyond tolerance', function () {
    $session = openMultiShift();

    stampSale($this, $session['id'], $this->eWalletMethod, 5000);   // emoney expected = 5,000

    $payload = fn (?string $reason) => [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5], // 105,000, cash variance 0
        ],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            // Declared WAON 4,000 vs emoney expected 5,000 → −1,000 category variance.
            ['tender_key' => 'waon', 'gross_amount' => 4000, 'cancel_amount' => 0, 'variance_reason' => $reason],
        ],
    ];

    $blocked = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        $payload(null),
    )->assertStatus(422);
    expect($blocked->json('code'))->toBe('VARIANCE_REASON_REQUIRED');

    $ok = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        $payload('WAON terminal short 1,000 — reprint pending'),
    )->assertOk()->json('data');
    expect($ok['status'])->toBe('settled');
});

it('requires a reason when the QR category rollup diverges beyond tolerance', function () {
    $session = openMultiShift();

    stampSale($this, $session['id'], $this->transferMethod, 8000);  // qr expected = 8,000 (transfer → qr)

    $payload = fn (?string $reason) => [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5], // 105,000 = float, cash variance 0
        ],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            // Declared QR = 6,000 + 3,000 = 9,000 vs expected 8,000 → +1,000 category variance.
            ['tender_key' => 'paypay', 'gross_amount' => 6000, 'cancel_amount' => 0, 'variance_reason' => $reason],
            ['tender_key' => 'rakuten_pay', 'gross_amount' => 3000, 'cancel_amount' => 0],
        ],
    ];

    // No reason anywhere in the QR category → blocked.
    $blocked = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        $payload(null),
    )->assertStatus(422);
    expect($blocked->json('code'))->toBe('VARIANCE_REASON_REQUIRED');

    // A reason on one QR row satisfies the category-level check → settles.
    $ok = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        $payload('PayPay slip over by 1,000 — investigating'),
    )->assertOk()->json('data');
    expect($ok['status'])->toBe('settled');
});

// =========================================================================
//  card_terminal — swiped on the SAME payment terminal as `card`, so it must
//  reconcile into the SAME credit anchor line
// =========================================================================

it('folds card_terminal sales into the credit anchor expected instead of leaving it at 0', function () {
    // The credit anchor tender keys off payment_method_code='card'. A
    // `card_terminal` payment sums under its own code, so without the merge in
    // TillSessionService::reconcile it is invisible to the anchor: expected
    // credit reads 0 while the bank's batch slip holds 9,000 of real money —
    // a phantom variance the cashier cannot settle without inventing a reason.
    $session = openMultiShift();

    stampSale($this, $session['id'], $this->cardTerminalMethod, 9000);

    $response = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5], // 105,000 = float, cash variance 0
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                // Terminal slip shows the 9,000 swipe. Declared == expected → settles clean.
                ['tender_key' => 'credit', 'gross_amount' => 9000, 'cancel_amount' => 0],
            ],
        ],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');

    $credit = TillSettlementTenderDetail::where('session_id', $session['id'])
        ->where('tender_key', 'credit')
        ->first();
    expect((float) $credit->expected_amount)->toBe(9000.0);   // not 0.0
    expect((float) $credit->declared_amount)->toBe(9000.0);
    expect((float) $credit->variance_amount)->toBe(0.0);
});

it('sums card and card_terminal into one credit anchor expected — they share the physical terminal', function () {
    $session = openMultiShift();

    stampSale($this, $session['id'], $this->cardMethod, 12000);
    stampSale($this, $session['id'], $this->cardTerminalMethod, 8000);

    $response = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5], // 105,000, cash variance 0
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                // One credit section on the slip covering both: 12,000 + 8,000.
                ['tender_key' => 'credit', 'gross_amount' => 20000, 'cancel_amount' => 0],
            ],
        ],
    )->assertOk()->json('data');

    expect($response['status'])->toBe('settled');

    $credit = TillSettlementTenderDetail::where('session_id', $session['id'])
        ->where('tender_key', 'credit')
        ->first();
    expect((float) $credit->expected_amount)->toBe(20000.0);
    expect((float) $credit->variance_amount)->toBe(0.0);
});

it('keeps card_terminal out of the drawer — it never touches expected_cash', function () {
    // type=card money settles into the bank, not the till. If card_terminal ever
    // leaked into $cashSales the drawer would read 7,000 over at every close.
    $session = openMultiShift();

    stampSale($this, $session['id'], $this->cardTerminalMethod, 7000);

    $response = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        [
            'closing_counts' => [
                ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
                ['denomination_id' => $this->jpy1000->id, 'quantity' => 5], // 105,000 = opening float, untouched
            ],
            'tender_details' => [
                ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
                ['tender_key' => 'credit', 'gross_amount' => 7000, 'cancel_amount' => 0],
            ],
        ],
    )->assertOk()->json('data');

    expect((float) $response['expected_cash_amount'])->toBe(105000.0);
    expect((float) $response['cash_variance_amount'])->toBe(0.0);
    expect($response['status'])->toBe('settled');
});

it('requires a reason when the credit anchor declared diverges beyond tolerance', function () {
    $session = openMultiShift();

    stampSale($this, $session['id'], $this->cardMethod, 12000); // credit expected = 12,000

    $payload = fn (?string $reason) => [
        'closing_counts' => [
            ['denomination_id' => $this->jpy10000->id, 'quantity' => 10],
            ['denomination_id' => $this->jpy1000->id, 'quantity' => 5], // 105,000, cash variance 0
        ],
        'tender_details' => [
            ['tender_key' => 'cash', 'gross_amount' => 0, 'cancel_amount' => 0],
            // Declared credit 15,000 vs expected 12,000 → +3,000 per-row variance.
            ['tender_key' => 'credit', 'gross_amount' => 15000, 'cancel_amount' => 0, 'variance_reason' => $reason],
        ],
    ];

    $blocked = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        $payload(null),
    )->assertStatus(422);
    expect($blocked->json('code'))->toBe('VARIANCE_REASON_REQUIRED');

    $ok = actingAsMultiCashier()->postJson(
        "/api/v1/pos/till/sessions/{$session['id']}/close",
        $payload('Terminal batch settled 3,000 higher — refund posted next day'),
    )->assertOk()->json('data');
    expect($ok['status'])->toBe('settled');

    $credit = TillSettlementTenderDetail::where('session_id', $session['id'])->where('tender_key', 'credit')->first();
    expect((float) $credit->variance_amount)->toBe(3000.0);
});
