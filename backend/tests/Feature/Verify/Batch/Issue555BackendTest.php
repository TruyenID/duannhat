<?php

/*
 * Issue #555 — independent re-verification of the backend financial-integrity
 * findings on `dev`. Every test drives a REAL production entry point
 * (OrderPaymentService, CustomerOrderService, CouponService, TillSessionService,
 * DispatchScheduledNotificationJob) — no hand-built end states.
 */

use App\Events\OrderItemAdded;
use App\Events\OrderPaymentRecorded;
use App\Jobs\DispatchScheduledNotificationJob;
use App\Jobs\NotificationChannelJob;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Coupon;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Denomination;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationRecipient;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\Till;
use App\Models\TillSession;
use App\Models\User;
use App\Services\Customer\CustomerOrderService;
use App\Services\Customer\OrderPaymentService;
use App\Services\Customer\OrderPricingCalculator;
use App\Services\Notification\EffectiveChannelService;
use App\Services\Order\Coupon\OrderCouponService;
use App\Services\Pos\TillSessionService;
use App\Services\Promotion\CouponService;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

// ---------------------------------------------------------------------------
//  Shared world
// ---------------------------------------------------------------------------

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);
    $this->till = Till::factory()->create([
        'till_code' => 'MAIN',
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
        'organization_id' => $this->orgId,
    ]);
    $this->cash = PaymentMethod::factory()->cash()->create(['organization_id' => $this->orgId]);
    $this->card = PaymentMethod::factory()->card()->create(['organization_id' => $this->orgId]);
    $this->sku = ProductSku::factory()->create();
});

/** Shop order settings for the branch (currency + tax + service charge). */
function vbtSettings(object $ctx, string $currency, float $serviceChargeRate = 0, bool $includeTax = false): ShopOrderSetting
{
    return ShopOrderSetting::updateOrCreate(
        ['branch_id' => $ctx->branch->id],
        [
            'currency_code' => $currency,
            'service_charge_rate' => $serviceChargeRate,
            'service_charge_tax_rate' => 0,
            'prices_include_tax' => $includeTax,
            'organization_id' => $ctx->orgId,
        ],
    );
}

/** Bare order header in a given status. */
function vbtOrder(object $ctx, string $status = 'open', array $extra = []): CustomerOrder
{
    return CustomerOrder::create(array_merge([
        'order_code' => 'ORD-'.date('Y').'-'.random_int(100000, 999999),
        'order_type' => 'dine_in',
        'status' => $status,
        'subtotal' => 0,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => $ctx->branch->id,
        'brand_id' => $ctx->brand->id,
        'organization_id' => $ctx->orgId,
    ], $extra));
}

/** Add a REAL cart line (the input), leaving pricing to production code. */
function vbtLine(object $ctx, CustomerOrder $order, float $qty, float $unitPrice, ?float $taxRate): CustomerOrderItem
{
    return CustomerOrderItem::create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $ctx->sku->id,
        'quantity' => $qty,
        'unit_price' => $unitPrice,
        'original_unit_price' => $unitPrice,
        'subtotal' => $qty * $unitPrice,
        'topping_subtotal' => 0,
        'tax_rate' => $taxRate,
        'status' => 'pending',
    ]);
}

function vbtOpenShift(object $ctx, string $currency = 'JPY', float $float = 100000): TillSession
{
    $session = app(TillSessionService::class)->open([
        'branch_id' => $ctx->branch->id,
        'organization_id' => $ctx->orgId,
        'brand_id' => $ctx->brand->id,
        'till_code' => 'MAIN',
        'currency_code' => $currency,
        'opening_counts' => [],
        'opened_by_id' => null,
    ]);

    // The float comes from the counted denominations; set it directly so the
    // reconcile assertions below have a known opening balance.
    $session->update(['opening_float_amount' => $float]);

    return $session->fresh();
}

// ===========================================================================
//  M1 — two sources of total must agree (no ceil-vs-half-up drift)
// ===========================================================================

it('M1: recalculateTotals and OrderPricingCalculator agree — no ceil drift (USD cents)', function () {
    $setting = vbtSettings($this, 'USD', serviceChargeRate: 10);
    $order = vbtOrder($this);
    // 3 x 3.33 = 9.99 @ 8% tax. ceil-to-cent would inflate tax and total.
    vbtLine($this, $order, 3, 3.33, 8.0);

    app(CustomerOrderService::class)->refreshOrderTotals($order);
    $order->refresh();

    $calc = app(OrderPricingCalculator::class);
    $priced = $calc->forOrder($order->fresh()->load('items'), $setting);

    // Independent half-up expectation: subtotal 9.99, tax 9.99*8% = 0.7992 -> 0.80,
    // service 9.99*10% = 0.999 -> 1.00, total 9.99 + 0.80 + 1.00 = 11.79.
    expect((float) $order->subtotal)->toBe(9.99)
        ->and((float) $order->tax_amount)->toBe(0.80)
        ->and((float) $order->service_charge)->toBe(1.00)
        ->and((float) $order->total_amount)->toBe(11.79)
        ->and(round($priced->totalAmount, 2))->toBe((float) $order->total_amount);

    // The single-rate `price()` entry (the second "source of total") must match.
    $single = $calc->price(9.99, 0.0, 8.0, 10.0, 'USD');
    expect(round($single['total_amount'], 2))->toBe(11.79);
});

it('M1: an integer-currency order lands on a whole unit — kiosk cannot wedge in paying', function () {
    $setting = vbtSettings($this, 'JPY');
    $order = vbtOrder($this);
    vbtLine($this, $order, 3, 333, 10.0); // 999 @ 10% => 99.9 -> half-up 100

    app(CustomerOrderService::class)->refreshOrderTotals($order);
    $order->refresh();

    expect((float) $order->tax_amount)->toBe(100.0)
        ->and((float) $order->total_amount)->toBe(1099.0)
        ->and(fmod((float) $order->total_amount, 1.0))->toBe(0.0);
});

// ===========================================================================
//  M2 — cash tip stays in the drawer, must be inside expected_cash
// ===========================================================================

it('M2: a cash tip recorded through OrderPaymentService lands in expected_cash', function () {
    vbtSettings($this, 'JPY');
    $session = vbtOpenShift($this, 'JPY', 100000);

    $order = vbtOrder($this, 'checkout', ['subtotal' => 3000, 'total_amount' => 3000]);

    // REAL payment path: tendered 5000, amount 3000, tip 500 -> change 1500,
    // drawer physically retains 3500.
    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 3000,
        'tip_amount' => 500,
        'tendered_amount' => 5000,
        'till_session_id' => $session->id,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    expect((float) $payment->change_amount)->toBe(1500.0); // tendered - amount - tip

    $recon = app(TillSessionService::class)->reconcile($session->fresh());

    expect((float) $recon['cash']['cash_sales'])->toBe(3000.0)
        ->and((float) $recon['cash']['cash_tips'])->toBe(500.0)
        // 100000 float + 3000 sale + 500 tip = 103500 physically in the drawer.
        ->and((float) $recon['cash']['expected_cash'])->toBe(103500.0);
});

it('M2 residual: the per-tender cash anchor excludes the tip that expected_cash includes', function () {
    vbtSettings($this, 'JPY');
    $session = vbtOpenShift($this, 'JPY', 0);
    $order = vbtOrder($this, 'checkout', ['subtotal' => 3000, 'total_amount' => 3000]);

    app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 3000,
        'tip_amount' => 500,
        'tendered_amount' => 5000,
        'till_session_id' => $session->id,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $recon = app(TillSessionService::class)->reconcile($session->fresh());

    // expected_cash (the number close() reconciles the drawer against) = 3500.
    expect((float) $recon['cash']['expected_cash'])->toBe(3500.0);
    // But the `cash` CATEGORY expectation still = SUM(amount) with no tip.
    expect((float) $recon['category_expected']['cash'])->toBe(3000.0);
});

// ===========================================================================
//  M3 — close() <-> payment race
// ===========================================================================

it('M3: a payment stamped onto a shift that settled mid-flight is rejected (409 NO_OPEN_SHIFT)', function () {
    vbtSettings($this, 'JPY');
    $session = vbtOpenShift($this, 'JPY', 0);
    $order = vbtOrder($this, 'checkout', ['subtotal' => 1000, 'total_amount' => 1000]);

    // The middleware resolved this id while the shift was open...
    $resolvedSessionId = $session->id;

    // ...then close() wins the race and settles the shift.
    app(TillSessionService::class)->close($session->fresh(), [
        'closing_counts' => [],
        'closing_cash_adjustment' => 0,
        'closing_note' => 'settled first',
        'tender_details' => [],
    ]);
    expect(TillSession::find($resolvedSessionId)->status->value ?? TillSession::find($resolvedSessionId)->status)
        ->toBe('settled');

    // The payment now arrives carrying the (stale) resolved id.
    app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'till_session_id' => $resolvedSessionId,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);
})->throws(HttpResponseException::class);

it('M3: no orphaned cash — the settled shift gains no payment', function () {
    vbtSettings($this, 'JPY');
    $session = vbtOpenShift($this, 'JPY', 0);
    $order = vbtOrder($this, 'checkout', ['subtotal' => 1000, 'total_amount' => 1000]);
    $sid = $session->id;

    app(TillSessionService::class)->close($session->fresh(), [
        'closing_counts' => [], 'closing_cash_adjustment' => 0,
        'closing_note' => 'x', 'tender_details' => [],
    ]);

    try {
        app(OrderPaymentService::class)->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $this->cash->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'till_session_id' => $sid,
            'received_by_id' => (string) Str::uuid(),
            'organization_id' => $this->orgId,
            'branch_id' => $this->branch->id,
            'brand_id' => $this->brand->id,
        ]);
    } catch (Throwable) {
        // expected
    }

    expect(OrderPayment::where('till_session_id', $sid)->count())->toBe(0)
        ->and((float) $order->fresh()->paid_amount)->toBe(0.0);
});

// ===========================================================================
//  M11 — OrderItemAdded must dispatch after commit
// ===========================================================================

it('M11: OrderItemAdded implements ShouldDispatchAfterCommit like its siblings', function () {
    expect(new OrderItemAdded(vbtOrder($this)))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    expect(class_implements(OrderPaymentRecorded::class))->toHaveKey(ShouldDispatchAfterCommit::class);
});

// ===========================================================================
//  M12 — confirm() / fail() row lock + terminal-status guard
// ===========================================================================

it('M12: a late fail() cannot overwrite an already-succeeded payment', function () {
    vbtSettings($this, 'JPY');
    $order = vbtOrder($this, 'checkout', ['subtotal' => 5000, 'total_amount' => 5000]);
    $svc = app(OrderPaymentService::class);

    // card is NOT auto-confirm -> lands `pending`
    $payment = $svc->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->card->id,
        'amount' => 5000,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);
    expect($payment->status->value ?? $payment->status)->toBe('pending');

    // confirm wins the race
    $confirmed = $svc->confirm($payment->fresh());
    expect($confirmed->status->value ?? $confirmed->status)->toBe('succeeded');
    expect((float) $order->fresh()->paid_amount)->toBe(5000.0);

    // the late terminal fail must NOT stomp it
    try {
        $svc->fail($payment->fresh(), ['reason' => 'late decline']);
        $this->fail('fail() should have 409d on a succeeded payment');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }

    expect($payment->fresh()->status->value ?? $payment->fresh()->status)->toBe('succeeded')
        ->and((float) $order->fresh()->paid_amount)->toBe(5000.0);
});

it('M12: a late confirm() cannot resurrect a failed payment', function () {
    vbtSettings($this, 'JPY');
    $order = vbtOrder($this, 'checkout', ['subtotal' => 5000, 'total_amount' => 5000]);
    $svc = app(OrderPaymentService::class);

    $payment = $svc->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->card->id,
        'amount' => 5000,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);

    $svc->fail($payment->fresh());
    expect($payment->fresh()->status->value ?? $payment->fresh()->status)->toBe('failed');

    try {
        $svc->confirm($payment->fresh());
        $this->fail('confirm() should have 409d on a failed payment');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
    }

    expect((float) $order->fresh()->paid_amount)->toBe(0.0);
});

// ===========================================================================
//  M14 — CouponService::recalculateOrderTotal is still a THIRD total formula
// ===========================================================================

it('M14: coupon apply re-derives tax off the discounted base — total agrees with the pricing engine', function () {
    $setting = vbtSettings($this, 'VND');
    $order = vbtOrder($this);
    vbtLine($this, $order, 1, 1000, 10.0);

    // Real pricing: subtotal 1000, tax 100, total 1100.
    app(CustomerOrderService::class)->refreshOrderTotals($order);
    $order->refresh();
    expect((float) $order->total_amount)->toBe(1100.0)
        ->and((float) $order->tax_amount)->toBe(100.0);

    Coupon::factory()->create([
        'code' => 'VBT200',
        'discount_type' => 'fixed',
        'discount_value' => 200,
        'min_order_subtotal' => 0,
        'max_discount_cap' => null,
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addDays(7),
        'usage_limit_total' => null,
        'usage_limit_per_customer' => 0,
        'times_used' => 0,
        'status' => 'draft',
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);

    // REAL production path.
    app(OrderCouponService::class)->apply($order->fresh(), 'VBT200');
    $order->refresh();

    expect((float) $order->discount_amount)->toBe(200.0);

    // What the pricing engine (the declared source of truth) says the total is:
    $engine = app(OrderPricingCalculator::class)
        ->forOrder($order->fresh()->load('items'), $setting->fresh());

    // taxable base 800 @ 10% => tax 80 => total 880.
    expect($engine->totalAmount)->toBe(880.0);

    // RESOLVED (#821 M14): apply() no longer re-sums the pre-discount tax. It
    // re-prices through the same engine, so tax comes off the DISCOUNTED base
    // (800 @ 10% = 80) and the persisted total matches the engine exactly — no
    // 20 VND over-charge lingering until checkout re-prices.
    expect((float) $order->total_amount)->toBe(880.0);
    expect((float) $order->total_amount)->toBe($engine->totalAmount);
    expect((float) $order->tax_amount)->toBe(80.0);
});

// ===========================================================================
//  M15 — partial refund flips the WHOLE payment to `refunded`
// ===========================================================================

it('M15: a PARTIAL refund marks the whole original refunded and blocks the remainder', function () {
    config(['payments.stripe_live_refunds_enabled' => false]);
    vbtSettings($this, 'JPY');
    $session = vbtOpenShift($this, 'JPY', 0);
    $order = vbtOrder($this, 'checkout', ['subtotal' => 10000, 'total_amount' => 10000]);
    $svc = app(OrderPaymentService::class);

    $payment = $svc->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 10000,
        'tendered_amount' => 10000,
        'till_session_id' => $session->id,
        'received_by_id' => (string) Str::uuid(),
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'brand_id' => $this->brand->id,
    ]);
    expect($payment->status->value ?? $payment->status)->toBe('succeeded');

    // Refund only 3,000 of the 10,000.
    $refund = $svc->refund($payment->fresh(), ['amount' => 3000, 'note' => 'partial']);
    expect((float) $refund->amount)->toBe(-3000.0);

    $original = $payment->fresh();

    // BUG: the ORIGINAL 10,000 payment is now `refunded` even though 7,000 was kept.
    expect($original->status->value ?? $original->status)->toBe('refunded');
    expect((float) $original->amount)->toBe(10000.0);

    // paid_amount nets correctly (that part was fixed by #528)...
    expect((float) $order->fresh()->paid_amount)->toBe(7000.0);

    // ...but the remaining 7,000 can NEVER be refunded: the guard demands
    // status === succeeded, and the partial already flipped it to refunded.
    try {
        $svc->refund($original, ['amount' => 2000]);
        $this->fail('a second partial refund unexpectedly succeeded');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(409);
        expect($e->getMessage())->toContain("Payment must be 'succeeded' to refund");
    }
});

// ===========================================================================
//  L3 — scheduled-notification orphan recovery
// ===========================================================================

it('L3: a notification marked dispatched with stranded deliveries re-queues them', function () {
    Bus::fake();

    $user = User::factory()->create();
    $notification = Notification::factory()->create([
        'organization_id' => $this->orgId,
        'is_dispatched' => true, // committed BEFORE the loop; worker then died
    ]);
    $recipient = NotificationRecipient::factory()->create([
        'notification_id' => $notification->id,
        'recipient_type' => $user->getMorphClass(),
        'recipient_id' => $user->id,
    ]);
    // Stranded: pending + attempts=0 => never handed to a worker.
    $orphan = NotificationDelivery::factory()->create([
        'notification_recipient_id' => $recipient->id,
        'channel' => 'in_app',
        'status' => 'pending',
        'attempts' => 0,
    ]);

    (new DispatchScheduledNotificationJob((string) $notification->id))
        ->handle(app(EffectiveChannelService::class));

    Bus::assertDispatched(NotificationChannelJob::class, 1);
});

// ===========================================================================
//  L4 — denomination currency filter
// ===========================================================================

it('L4: the POS close path rejects a foreign-currency denomination', function () {
    vbtSettings($this, 'JPY');
    $session = vbtOpenShift($this, 'JPY', 0);

    $usd = Denomination::factory()->create([
        'value' => 100,
        'currency_code' => 'USD',
        'kind' => 'note',
        'organization_id' => $this->orgId,
    ]);

    try {
        app(TillSessionService::class)->close($session->fresh(), [
            'closing_counts' => [
                ['denomination_id' => $usd->id, 'quantity' => 1],
            ],
            'closing_cash_adjustment' => 0,
            'closing_note' => 'mixed currency',
            'tender_details' => [],
        ]);
        $this->fail('a USD $100 bill was accepted into a JPY drawer');
    } catch (HttpResponseException $e) {
        expect($e->getResponse()->getStatusCode())->toBe(422);
        expect($e->getResponse()->getContent())->toContain('DENOMINATION_CURRENCY_MISMATCH');
    }

    // The shift must stay OPEN — nothing was corrupted.
    expect(TillSession::find($session->id)->status->value ?? TillSession::find($session->id)->status)
        ->toBe('open');
});
