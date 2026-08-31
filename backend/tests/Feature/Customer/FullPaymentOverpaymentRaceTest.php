<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\StripePaymentService;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * plan-020 — MONEY / full-flow overpayment + full-after-split ledger desync.
 *
 * The "full" webhook (metadata.flow = 'full', matched on the order's stored
 * stripe_payment_intent_id) used to blindly set paid_amount = total_amount with
 * NO lock and NO ledger re-sum. A "full" intent charges the remaining computed
 * at CREATION time; if a split payment lands between creation and the webhook,
 * hardcoding paid_amount = total clamps the order to total and HIDES the real
 * card overpayment (the ledger sums past total). This exercises that the full
 * flow now enforces the same overpayment invariant as the split flow.
 */
beforeEach(function () {
    config()->set('services.stripe.key', 'pk_test_dummy');
    config()->set('services.stripe.secret', 'sk_test_dummy');
    config()->set('services.stripe.webhook_secret', 'whsec_test_dummy');
    config()->set('services.stripe.currency', 'jpy');

    $this->organization = Organization::factory()->create();
    $this->brand = Brand::factory()->create();
    $this->branch = Branch::factory()->create();

    $this->order = CustomerOrder::factory()->create([
        'organization_id' => $this->organization->id,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'total_amount' => 5000,
        'paid_amount' => 0,
        'status' => CustomerOrderStatusEnum::Open->value,
        'stripe_payment_intent_id' => null,
    ]);
});

function fullPmtSplitIntent(string $id, int $amount, CustomerOrder $order, Branch $branch, Organization $org): PaymentIntent
{
    return PaymentIntent::constructFrom([
        'id' => $id,
        'object' => 'payment_intent',
        'amount' => $amount,
        'currency' => 'jpy',
        'status' => 'succeeded',
        'metadata' => [
            'flow' => 'split',
            'order_id' => $order->id,
            'order_code' => $order->order_code,
            'branch_id' => $branch->id,
            'organization_id' => $org->id,
        ],
    ]);
}

function fullFlowIntent(string $id, int $amount): PaymentIntent
{
    return PaymentIntent::constructFrom([
        'id' => $id,
        'object' => 'payment_intent',
        'amount' => $amount,
        'currency' => 'jpy',
        'status' => 'succeeded',
        // flow omitted → defaults to 'full'; matched via stripe_payment_intent_id.
        'metadata' => [],
    ]);
}

it('rejects a full-flow payment that would overpay after a concurrent split committed', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    // Guest A pays a ¥3,000 split slice — it commits (paid_amount = 3000).
    $service->markOrderPaidFromIntent(fullPmtSplitIntent('pi_split_first', 3000, $this->order, $this->branch, $this->organization));

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(3000.0);

    // Guest B had opened "Thanh toán toàn bộ" earlier — the full intent was
    // created while paid_amount was still 0, so it charges the whole ¥5,000
    // remaining. Its id is stored on the order (as createFullPaymentIntent does).
    $this->order->update(['stripe_payment_intent_id' => 'pi_full_stale']);

    // The full webhook lands AFTER the split committed. Old code: record ¥5,000
    // + set paid_amount = total (5000) → ledger 3000+5000 = 8000, overpayment
    // of ¥3,000 hidden. New code: guard re-sums the ledger and REJECTS.
    $service->markOrderPaidFromIntent(fullFlowIntent('pi_full_stale', 5000));

    $this->order->refresh();

    // Rejected: no row written, paid_amount untouched, order still open.
    expect((float) $this->order->paid_amount)->toBe(3000.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Open->value);
    expect(OrderPayment::where('reference_no', 'pi_full_stale')->count())->toBe(0);

    // Ledger integrity: collected never exceeds total_amount.
    $ledger = (float) OrderPayment::where('customer_order_id', $this->order->id)
        ->whereIn('status', [PaymentStatusEnum::Succeeded->value, PaymentStatusEnum::Refunded->value])
        ->sum('amount');
    expect($ledger)->toBe(3000.0);
    expect($ledger)->toBeLessThanOrEqual((float) $this->order->total_amount);
});

it('accepts a legitimate full-after-split closing slice with no ledger desync', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    // Guest A pays ¥2,000 split (commits, paid_amount = 2000).
    $service->markOrderPaidFromIntent(fullPmtSplitIntent('pi_split_ok', 2000, $this->order, $this->branch, $this->organization));

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(2000.0);

    // Guest B selects "Thanh toán toàn bộ" NOW — createFullPaymentIntent charges
    // the remaining (total 5000 − paid 2000 = 3000).
    $this->order->update(['stripe_payment_intent_id' => 'pi_full_close']);
    $service->markOrderPaidFromIntent(fullFlowIntent('pi_full_close', 3000));

    $this->order->refresh();

    // Order settles exactly — paid_amount == total, status Closed.
    expect((float) $this->order->paid_amount)->toBe(5000.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);

    // The full-payment row records the ACTUAL charged slice (3000), not total.
    $fullRow = OrderPayment::where('reference_no', 'pi_full_close')->first();
    expect($fullRow)->not->toBeNull();
    expect((float) $fullRow->amount)->toBe(3000.0);

    // No ledger desync: sum of ledger rows == paid_amount == total_amount.
    $ledger = (float) OrderPayment::where('customer_order_id', $this->order->id)
        ->whereIn('status', [PaymentStatusEnum::Succeeded->value, PaymentStatusEnum::Refunded->value])
        ->sum('amount');
    expect($ledger)->toBe(5000.0);
    expect($ledger)->toBe((float) $this->order->paid_amount);
});

it('is idempotent — a replayed full webhook is a no-op, not an overpayment rejection', function () {
    $service = new StripePaymentService(Mockery::mock(StripeClient::class));

    $this->order->update(['stripe_payment_intent_id' => 'pi_full_replay']);
    $intent = fullFlowIntent('pi_full_replay', 5000);

    $service->markOrderPaidFromIntent($intent);
    $service->markOrderPaidFromIntent($intent); // replay of the exact same intent

    $this->order->refresh();
    expect((float) $this->order->paid_amount)->toBe(5000.0);
    expect($this->order->status->value)->toBe(CustomerOrderStatusEnum::Closed->value);
    expect(OrderPayment::where('reference_no', 'pi_full_replay')->count())->toBe(1);
});
