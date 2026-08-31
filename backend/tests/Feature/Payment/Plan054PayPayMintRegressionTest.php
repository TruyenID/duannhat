<?php

/**
 * plan-054 — the mint path actually reaches PayPay.
 *
 * Regression for a bug that made `createQrCode()` fail on EVERY call with a 500:
 * the service handed `preparePayPayQrAttempt()` the `payment_gateway_connection_options`
 * ROW id, while the authority port looks that value up as `option_id` — the
 * CATALOG option id. Nothing matched, the preparation was refused, and no QR was
 * ever minted. The Stripe twin passes the catalog id and was always correct.
 *
 * It reached main because the only class doing network I/O was `final`, so no
 * test above it could run without calling PayPay for real. The class is no longer
 * sealed; this test is what that buys.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Customer\PayPayPaymentService;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\PayPay\PayPayQrSplitIntent;
use Illuminate\Support\Str;

uses()->group('payment');

beforeEach(function () {
    config([
        'services.paypay.api_key' => 'a_key',
        'services.paypay.api_secret' => 'a_secret',
        'services.paypay.merchant_id' => '991602796635988897',
        'services.paypay.environment' => 'sandbox',
    ]);
});

function plan054MintOrder(): CustomerOrder
{
    $consoleOrganizationId = (string) Str::uuid();

    $organization = Organization::factory()->create(['console_organization_id' => $consoleOrganizationId]);
    $brand = Brand::factory()->create(['console_organization_id' => $consoleOrganizationId]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'JPY',
    ]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ]);

    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $order->organization_id,
        'currency_code' => 'JPY',
    ]);

    return $order;
}

function plan054FakeQrClient(array $created = []): PayPayQrCodeClient
{
    $fake = Mockery::mock(PayPayQrCodeClient::class)->makePartial();
    $fake->shouldReceive('create')->andReturn(array_merge([
        'code_id' => '04-fake',
        'url' => 'https://qr-stg.sandbox.paypay.ne.jp/fake',
        'deeplink' => 'paypay://payment?link_key=fake',
        'expires_at' => now()->addMinutes(5)->getTimestamp(),
        'amount' => 3000,
        'currency' => 'JPY',
    ], $created));
    $fake->shouldReceive('delete')->andReturn(true);

    app()->instance(PayPayQrCodeClient::class, $fake);

    return $fake;
}

it('mints a QR and reserves the attempt it will be matched by', function () {
    plan054FakeQrClient();
    $order = plan054MintOrder();

    $payload = app(PayPayPaymentService::class)->createQrCode(orderSnapshot($order));

    expect($payload['qr_url'])->toContain('qr-stg.sandbox.paypay.ne.jp')
        ->and($payload['merchant_payment_id'])->toStartWith(PayPayQrCodeClient::MPID_PREFIX)
        ->and($payload['amount'])->toBe(3000.0)
        // Server-anchored, so a skewed client clock cannot expire a live code.
        ->and($payload['expires_in_seconds'])->toBeGreaterThan(0);

    $attempt = PaymentAttempt::query()->where('customer_order_id', $order->id)->sole();

    // The attempt is what a webhook matches on, so it must carry the same id the
    // code was minted under, and must be filed as customer-web rather than the
    // `pos` the skeleton defaults to.
    expect($attempt->provider_object_id)->toBe($payload['merchant_payment_id'])
        ->and($attempt->channel->value ?? $attempt->channel)->toBe(PaymentChannelEnum::CustomerWeb->value);
});

it('charges what is outstanding rather than the order total', function () {
    plan054FakeQrClient(['amount' => 1200]);
    $order = plan054MintOrder();
    $order->update(['paid_amount' => 1800]);

    expect(app(PayPayPaymentService::class)->createQrCode(orderSnapshot($order))['amount'])->toBe(1200.0);
});

it('honours a caller-supplied share for split bills', function () {
    // The split flow sends each payer's own slice; deriving total-minus-paid would
    // hand the first of four payers a code for the whole bill.
    plan054FakeQrClient(['amount' => 750]);
    $order = plan054MintOrder();

    expect(app(PayPayPaymentService::class)->createQrCode(orderSnapshot($order), 750.0)['amount'])->toBe(750.0);
});

// ─── #1296: the dine-in mint carries the payer's split intent ────────────────

it('parks the declared split against the code so settlement can attribute it', function () {
    plan054FakeQrClient(['amount' => 750]);
    $order = plan054MintOrder();

    $payload = app(PayPayPaymentService::class)->createQrCode(orderSnapshot($order), 750.0, [
        'split_type' => 'by_items',
        'item_allocations' => [['item_id' => 'item-salad', 'units' => 1]],
    ]);

    // PayPay's create call carries no metadata of ours, and the ledger row is
    // written somewhere else entirely — the merchant payment id is the only
    // thread between the two.
    expect(PayPayQrSplitIntent::recall($payload['merchant_payment_id']))->toBe([
        'split_type' => 'by_items',
        'split_count' => null,
        'item_allocations' => [['item_id' => 'item-salad', 'units' => 1]],
    ]);
});

it('re-mints rather than resuming when the payer switched to a same-priced dish', function () {
    plan054FakeQrClient(['amount' => 500]);
    $order = plan054MintOrder();
    $service = app(PayPayPaymentService::class);

    $salad = $service->createQrCode(orderSnapshot($order), 500.0, [
        'split_type' => 'by_items',
        'item_allocations' => [['item_id' => 'item-salad', 'units' => 1]],
    ]);

    // Same ¥500, different dish. Amount alone would resume the salad code and
    // credit a dish the payer just deselected.
    $soup = $service->createQrCode(orderSnapshot($order), 500.0, [
        'split_type' => 'by_items',
        'item_allocations' => [['item_id' => 'item-soup', 'units' => 1]],
    ]);

    expect($soup['merchant_payment_id'])->not->toBe($salad['merchant_payment_id']);
    expect(PayPayQrSplitIntent::recall($soup['merchant_payment_id'])['item_allocations'])
        ->toBe([['item_id' => 'item-soup', 'units' => 1]]);
    // The superseded code's claim goes with it.
    expect(PayPayQrSplitIntent::recall($salad['merchant_payment_id']))->toBeNull();
});

it('still resumes an unchanged code, so a reload does not restart the countdown', function () {
    plan054FakeQrClient(['amount' => 500]);
    $order = plan054MintOrder();
    $service = app(PayPayPaymentService::class);

    $split = [
        'split_type' => 'by_items',
        'item_allocations' => [['item_id' => 'item-salad', 'units' => 1]],
    ];

    $first = $service->createQrCode(orderSnapshot($order), 500.0, $split);
    $second = $service->createQrCode(orderSnapshot($order), 500.0, $split);

    expect($second['merchant_payment_id'])->toBe($first['merchant_payment_id']);
});
