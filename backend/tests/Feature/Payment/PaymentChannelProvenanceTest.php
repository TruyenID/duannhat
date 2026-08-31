<?php

/**
 * #2612 — a payment must carry the app it came from, on EVERY write path.
 *
 * `order_payments.channel` is the server-owned provenance column. Production
 * measured 2026-08-12: workstation-forwarded payments had it NULL 128/128 while
 * customer-web payments had it stamped 40/40 — and the split is not the route,
 * it is `payment_methods.is_auto_confirm`.
 *
 * An auto-confirm tender (cash, card_terminal) does not go through the legacy
 * `OrderPayment::create()` array that carries `'channel' => $channel`. It is
 * written by the orchestrator (`recordTender`) and then patched back to parity
 * post-write, because `PaymentTenderPayload` is a canonical mutation payload and
 * extending it would shift idempotency hashes for offline replays. That patch
 * already restores `reference_no`, `tender_key`, `gateway_option_id` and
 * `gateway_connection_id` — every field of exactly this class. `channel` was
 * never added to it, so the one population that reaches Cloud through a device
 * is the one population with no provenance.
 *
 * Why it costs money rather than being untidy data: without this column the only
 * way to ask "which payments did the shop's own terminal take?" is to read NULL
 * as "workstation". That inference produced two wrong conclusions in a row on
 * #2410, and it blocks #2580 outright — the guard there ("Cloud may not refund a
 * workstation payment while its shift is open") cannot be written against a
 * column that is empty for every workstation payment.
 *
 * Falsification (run, not assumed): dropping the `'channel'` line from
 * `OrderPaymentOrchestrationCompat::recordAutoConfirmTender()` turns the first
 * two cases red on a NULL channel. The third case stays green under that
 * falsification by design — it goes through the non-auto-confirm path, so it
 * pins that this fix did not disturb the path that was already correct.
 */

use App\Models\OrderPayment;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentChannelEnum;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures;
    $this->fixtures->bind();
    $this->fixtures->seedConnection();
    $this->fixtures->publishInitialPolicyRevision();
    $this->fixtures->seedCashPaymentMethod();
});

it('#2612 stamps channel=workstation on an auto-confirm cash tender taken by a workstation', function () {
    $device = $this->fixtures->seedWorkstationDevice();
    $order = $this->fixtures->seedCheckoutOrder(1500.0);

    $this->withHeaders([
        'Authorization' => "Bearer {$device->device_token}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
    ])->assertCreated();

    $payment = OrderPayment::query()->where('customer_order_id', $order->id)->firstOrFail();

    expect($payment->channel)->toBe(PaymentChannelEnum::Workstation->value);
});

it('#2612 stamps the kiosk channel on the kiosk route — the value is the real transport, not a constant', function () {
    $device = $this->fixtures->seedKioskDevice();
    $order = $this->fixtures->seedCheckoutOrder(1500.0);

    $this->withHeaders([
        'Authorization' => "Bearer {$device->device_token}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/kiosk/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
        // The kiosk route has no auto-tender: cash must state what was handed over.
        'tendered_amount' => 1500,
    ])->assertCreated();

    $payment = OrderPayment::query()->where('customer_order_id', $order->id)->firstOrFail();

    // Two cases, one assertion each, precisely so a fix that hard-codes
    // 'workstation' cannot pass both.
    expect($payment->channel)->toBe(PaymentChannelEnum::Kiosk->value);
});

it('#2612 leaves the already-correct non-auto-confirm path untouched', function () {
    $device = $this->fixtures->seedWorkstationDevice();
    $order = $this->fixtures->seedCheckoutOrder(1500.0);

    // A method that must be confirmed does NOT take the orchestrator tender
    // path, so it has always reached the legacy create() array that stamps the
    // column. Pinned here so the fix is provably additive.
    PaymentMethod::query()->where('code', 'cash')->update(['is_auto_confirm' => false]);

    $this->withHeaders([
        'Authorization' => "Bearer {$device->device_token}",
        'Idempotency-Key' => (string) Str::uuid(),
    ])->postJson('/api/v1/workstation/payments', [
        'order_id' => $order->id,
        'payment_method' => 'cash',
        'amount' => 1500,
    ])->assertCreated();

    $payment = OrderPayment::query()->where('customer_order_id', $order->id)->firstOrFail();

    expect($payment->channel)->toBe(PaymentChannelEnum::Workstation->value);
});
