<?php

/**
 * plan-054 M9 (T9.3) — the REVERSE race: cash at the drawer after PayPay already paid.
 *
 * T9.1 covers the direction that saves the customer: the PayPay payment bumps
 * `customer_orders.updated_at`, the workstation's 5 s
 * `GET /workstation/orders?updated_since=` tick picks it up, pos-web shows
 * `remaining_amount = 0` and the cashier never asks for the money.
 *
 * This file covers the ≤5 s window BEFORE that tick, where the cashier is
 * looking at a ticket that Cloud already considers settled:
 *
 *   1. the customer scans the QR and PayPay books the money in Cloud;
 *   2. within the pull window the cashier takes cash at the drawer;
 *   3. the workstation syncs that cash UP to `POST /workstation/payments`.
 *
 * Cloud must refuse step 3 — one bill, one payment — and it must SAY SO. The
 * refusal is terminal for the workstation's sync queue (a 4xx dead-letters; only
 * 5xx is retried), so a quiet 409 means the cashier is standing on physical cash
 * that exists in no ledger anywhere, and nobody learns until the shift's 過不足
 * comes up over at 精算.
 *
 * The wall itself is the plain order-status guard in
 * `OrderPaymentService::create()` — paying in full closes the order, and a
 * closed order takes no payments. It was already correct and already left
 * exactly one row; what it did NOT do was make any noise. The alarm
 * (`workstation_payment_stranded_at_the_drawer`, `payment_orchestration`
 * channel) is the part this file added.
 *
 * Sibling: `tests/Feature/Pos/Till/GapPreviewOnlineChannelTest.php` walls the
 * same money out of the gap-reconciliation panel — a cashier can never claim
 * online money that never touched the drawer. Same principle, opposite
 * direction.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Device;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\DeviceStatusEnum;
use App\Omnify\Enums\DeviceTypeEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses()->group('payment');

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
        'currency' => 'JPY',
    ]);

    // The PayPay currency guard reads the priced currency off the shop setting,
    // not off branches.currency — same as the Stripe funnel.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'currency_code' => 'JPY',
    ]);

    $this->wsToken = Str::random(64);
    $this->wsDevice = Device::factory()->create([
        'type' => DeviceTypeEnum::Workstation,
        'status' => DeviceStatusEnum::Active,
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    /** The workstation's sync-UP of a payment its cashier already collected. */
    $this->syncUpDrawerCash = fn (CustomerOrder $order, int $amount, int $tip = 0) => $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', array_filter([
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => $amount,
        'tip_amount' => $tip ?: null,
    ]));
});

function plan054ReverseRaceOrder(object $ctx, float $total): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $ctx->orgId,
        'brand_id' => $ctx->brand->id,
        'branch_id' => $ctx->branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'subtotal' => $total,
        'discount_amount' => 0,
        'total_amount' => $total,
        'paid_amount' => 0,
    ]);
}

/** The customer settles their own bill from their phone. */
function plan054ReverseRacePayPay(CustomerOrder $order, float $amount, string $mpid): array
{
    return app(OrderPaymentService::class)->recordPayPayPaymentByOrderId((string) $order->id, $mpid, $amount, 'JPY');
}

function plan054ReverseRaceSpyOnLog(): MockInterface
{
    $logger = Log::spy();
    $logger->shouldReceive('channel')->andReturnSelf();

    return $logger;
}

it('refuses the drawer cash once PayPay has settled the whole bill, and leaves exactly one payment', function () {
    $order = plan054ReverseRaceOrder($this, 3000);

    expect(plan054ReverseRacePayPay($order, 3000.0, 'tempoqr-reverse-race')['recorded'])->toBeTrue();

    // ── the cashier, still on a pre-tick screen, takes ¥3,000 in cash ────────
    $response = ($this->syncUpDrawerCash)($order, 3000);

    // Paying in full closes the order, and a closed order accepts no payment.
    $response->assertStatus(409)
        ->assertJsonPath(
            'message',
            "Order must be in 'checkout' or 'paying' status to accept a payment. Current: closed",
        );

    // The number that actually matters: one bill, one row. Never two.
    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1);

    $onlyPayment = OrderPayment::where('customer_order_id', $order->id)->sole();

    expect($onlyPayment->reference_no)->toBe('tempoqr-reverse-race')
        ->and($onlyPayment->channel)->toBe(PaymentChannelEnum::CustomerWeb->value)
        ->and((float) $onlyPayment->amount)->toBe(3000.0)
        // …and the order is not quietly over-collected on the way out.
        ->and((float) $order->fresh()->paid_amount)->toBe(3000.0)
        ->and($order->fresh()->status)->toBe(CustomerOrderStatusEnum::Closed);
});

it('alarms with the order and the cash the cashier is holding', function () {
    // A 4xx is TERMINAL for the workstation's sync queue — it dead-letters
    // rather than retrying — so if this log is silent the ¥3,000 in the drawer
    // is discovered only as an unexplained surplus at 精算.
    $order = plan054ReverseRaceOrder($this, 3000);
    plan054ReverseRacePayPay($order, 3000.0, 'tempoqr-alarm');

    $logger = plan054ReverseRaceSpyOnLog();

    ($this->syncUpDrawerCash)($order, 3000, tip: 200)->assertStatus(409);

    // Twice: MoneyOrchestrationLog writes every money failure to both the
    // payment_orchestration channel and the default one, because only the
    // latter reaches alerting (#1244). The spy collapses both, so the count
    // is the only visible evidence the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) use ($order): bool {
            return $message === '[payments.stranded] workstation_payment_stranded_at_the_drawer'
                && $context['order_id'] === (string) $order->id
                && $context['order_code'] === $order->order_code
                && $context['order_status'] === CustomerOrderStatusEnum::Closed->value
                // Cash + tip: every yen physically in the till with no row.
                && $context['stranded_amount'] === 3200.0
                && $context['amount'] === 3000.0
                && $context['tip_amount'] === 200.0
                && $context['payment_method'] === 'cash'
                && $context['http_status'] === 409
                && $context['order_paid_amount'] === 3000.0
                && $context['order_total_amount'] === 3000.0
                && $context['device_id'] === (string) test()->wsDevice->id
                && $context['branch_id'] === (string) test()->branch->id;
        })
        ->twice();
});

it('does not alarm when the sync-UP lands normally', function () {
    // Negative control. Without it the alarm could be firing on every payment
    // and the test above would still be green.
    $order = plan054ReverseRaceOrder($this, 3000);

    $logger = plan054ReverseRaceSpyOnLog();

    ($this->syncUpDrawerCash)($order, 3000)->assertCreated();

    $logger->shouldNotHaveReceived('error');
    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1);
});

it('still lets the drawer collect the remainder after a PARTIAL PayPay payment', function () {
    // The wall must not be wider than the race. A half-paid order is exactly the
    // case where the cashier SHOULD be taking money — refusing here would strand
    // cash in the opposite direction.
    $order = plan054ReverseRaceOrder($this, 5000);
    plan054ReverseRacePayPay($order, 2000.0, 'tempoqr-partial');

    ($this->syncUpDrawerCash)($order, 3000)->assertCreated();

    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(2)
        ->and((float) $order->fresh()->paid_amount)->toBe(5000.0);
});

it('refuses and alarms when the drawer takes the FULL total on a partially-PayPaid order', function () {
    // The same race one beat earlier: the QR covered half the bill, the cashier
    // is still holding the pre-tick ticket and rings up the whole total. The
    // order is still `paying` so the status guard does not fire — the
    // overpayment guard does, with its own structured 422. Different door, same
    // ¥5,000 sitting in the till unrecorded, so the alarm must cover it too.
    $order = plan054ReverseRaceOrder($this, 5000);
    plan054ReverseRacePayPay($order, 2000.0, 'tempoqr-overpay');

    $logger = plan054ReverseRaceSpyOnLog();

    ($this->syncUpDrawerCash)($order, 5000)
        ->assertStatus(422)
        ->assertJsonPath('code', 'overpayment_blocked');

    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1)
        ->and((float) $order->fresh()->paid_amount)->toBe(2000.0);

    // Twice: MoneyOrchestrationLog writes every money failure to both the
    // payment_orchestration channel and the default one, because only the
    // latter reaches alerting (#1244). The spy collapses both, so the count
    // is the only visible evidence the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.stranded] workstation_payment_stranded_at_the_drawer'
            && $context['reason_code'] === 'overpayment_blocked'
            && $context['http_status'] === 422
            && $context['stranded_amount'] === 5000.0)
        ->twice();
});

it('keeps refusing the same queue item on retry — one row, no drift', function () {
    // The workstation retries its queue. Replaying the dead-lettered item (or a
    // build that retries a 4xx by mistake) must never bank the second payment.
    $order = plan054ReverseRaceOrder($this, 3000);
    plan054ReverseRacePayPay($order, 3000.0, 'tempoqr-retry');

    $key = (string) Str::uuid();
    $retry = fn () => $this->withHeaders([
        'Authorization' => "Bearer {$this->wsToken}",
        'Idempotency-Key' => $key,
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 3000,
    ]);

    $retry()->assertStatus(409);
    $retry()->assertStatus(409);
    $retry()->assertStatus(409);

    expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1)
        ->and((float) $order->fresh()->paid_amount)->toBe(3000.0);
});
