<?php

/**
 * #1232 — the pilot gate for plan-054 PayPay dynamic QR.
 *
 * The issue lists what must be true before a real shop is switched on. Most of
 * it needs a human (a phone scanning a sandbox code, a webhook registered in the
 * PayPay dashboard, an operations ruling on manual refunds). Two of its
 * assertions are NOT human-only and were nevertheless untested, so this file
 * closes them:
 *
 *  - #1232 item 2 — "cut the frontend poll, leave only the webhook: the order
 *    must still reach paid". Everything that exists today either drives the
 *    APPLICATOR directly (Plan054PayPayWebhookLedgerTest, which starts from an
 *    inbox row someone else created) or posts an UNMATCHED notification through
 *    the HTTP route (Plan048ProviderWebhookIntakeTest C5, which ends at
 *    `paypay_no_matching_attempt`). Neither one walks the whole road — signed
 *    HTTP request → signature verified → inbox → job → applicator → ledger —
 *    for a QR a customer actually holds, which is the road a real notification
 *    takes and the only one that answers "does the money land without a poll?".
 *
 *  - #1232 item 1, bullets 3-4 — the settled `order_payments` row must carry
 *    BOTH `gateway_connection_id` and `gateway_option_id`, and the inbox row
 *    must read `orchestrator_paypay_attempt_recovered`. No test asserts the
 *    option stamp on a PayPay row today (grep `gateway_option_id` in tests/):
 *    the attempt factory fills `connection_option_id` with whatever option row
 *    happens to exist, so a value is always present and always ignored. Here the
 *    QR capability is created deliberately and the ledger row is required to
 *    name THAT one — otherwise a settled payment cannot be attributed to the
 *    capability that took it.
 *
 *  - #1232 item 7 — two phones on one order. The premise ("the later mint kills
 *    the earlier phone's code") is only half true since the resume path landed,
 *    and the difference matters to the operator: an unchanged bill hands both
 *    phones the SAME code and kills nothing. The poll must name the live code so
 *    a superseded phone can tell its own is dead instead of showing a healthy
 *    countdown over a dead QR.
 *
 * What this file deliberately does NOT claim: that PayPay will ever call us. The
 * webhook is not registered at the provider (issue item 2, first checkbox) and
 * no code here can register it.
 */

use App\Jobs\Payment\ProcessPaymentProviderEventJob;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayConnectionOption;
use App\Models\PaymentGatewayOption;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\PayPayPaymentService;
use App\Services\Payment\Gateway\Commands\CancelPaymentCommand;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RefundPaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrieveRefundCommand;
use App\Services\Payment\Gateway\Commands\VerifyWebhookCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\PayPay\PayPayPaymentGateway;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use Database\Seeders\PaymentGatewayCatalogSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses()->group('payment');

/** Container key the registry resolves the webhook-path gateway through. */
const ISSUE1232_GATEWAY = 'tests.issue1232.gateway';

const ISSUE1232_WEBHOOK_SECRET = 'paypay_whsec_issue1232';

/**
 * A gateway that verifies webhooks FOR REAL and answers one scripted retrieve.
 *
 * Verification delegates to the production adapter on purpose: the point of the
 * test is that a correctly signed payload gets through the real HMAC check, so a
 * fake that accepts a magic header string would prove nothing. Everything else
 * throws, so a refactor that reaches for another gateway call to settle the
 * money fails loudly instead of quietly taking a second route to the ledger.
 */
function issue1232Gateway(GatewayPaymentResult $result): PaymentGatewayContract
{
    return new class($result) implements PaymentGatewayContract
    {
        public int $retrieveCalls = 0;

        private readonly PayPayPaymentGateway $real;

        public function __construct(private readonly GatewayPaymentResult $result)
        {
            $this->real = new PayPayPaymentGateway;
        }

        public function capabilities(GatewayConnectionData $connection): CapabilitySet
        {
            return $this->real->capabilities($connection);
        }

        public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
        {
            return $this->real->verifyWebhook($command);
        }

        /**
         * #2938 — cùng lý lẽ với `verifyWebhook`: phân giải connection là một
         * bước của đường webhook thật, nên nó đi qua adapter thật.
         *
         * @param  array<string, mixed>  $payload
         */
        public function identifyConnection(array $payload): ?ConnectionLocator
        {
            return $this->real->identifyConnection($payload);
        }

        public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
        {
            $this->retrieveCalls++;

            return $this->result;
        }

        public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
        {
            throw new RuntimeException('unused');
        }

        public function capture(CapturePaymentCommand $command): GatewayPaymentResult
        {
            throw new RuntimeException('unused');
        }

        public function cancel(CancelPaymentCommand $command): GatewayPaymentResult
        {
            throw new RuntimeException('unused');
        }

        public function refund(RefundPaymentCommand $command): GatewayRefundResult
        {
            throw new RuntimeException('unused');
        }

        public function retrieveRefund(RetrieveRefundCommand $command): GatewayRefundResult
        {
            throw new RuntimeException('unused');
        }
    };
}

/** Point the `paypay` driver slot at the stub above. */
function issue1232UseGateway(GatewayPaymentResult $result): PaymentGatewayContract
{
    $gateway = issue1232Gateway($result);

    app()->instance(ISSUE1232_GATEWAY, $gateway);
    config(['payments.gateway_drivers.paypay' => ISSUE1232_GATEWAY]);
    app()->forgetInstance(PaymentGatewayRegistry::class);

    return $gateway;
}

/**
 * A QR client that refuses to answer anything.
 *
 * This is the class the CUSTOMER'S POLL goes through
 * (`PayPayPaymentService::syncStatus` → `PayPayQrCodeClient::retrieve`) and the
 * one the sweeper goes through (`findPayment`). Binding a throwing instance is
 * how "the poll is switched off" is stated in code: if the order still reaches
 * paid, the money can only have come from the webhook.
 */
function issue1232PollIsOff(): void
{
    app()->instance(PayPayQrCodeClient::class, new class extends PayPayQrCodeClient
    {
        public function retrieve(
            GatewayConnectionData $connection,
            string $merchantPaymentId,
            string $correlationId,
        ): array {
            throw new RuntimeException('The customer poll is switched off for this test.');
        }

        public function findPayment(
            GatewayConnectionData $connection,
            string $merchantPaymentId,
            string $correlationId,
        ): ?array {
            throw new RuntimeException('The sweeper is switched off for this test.');
        }
    });
}

/**
 * Everything a live QR needs, wired the way the mint leaves it.
 *
 * @return array{order: CustomerOrder, attempt: PaymentAttempt, connection: PaymentGatewayConnection, option: PaymentGatewayOption, mpid: string, merchant: string}
 */
function issue1232LiveQr(): array
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
        'organization_id' => $organization->id,
        'currency_code' => 'JPY',
    ]);

    $provider = PaymentGatewayProvider::factory()->create([
        'code' => PaymentGatewayProviderCodeEnum::Paypay,
        'is_active' => true,
    ]);

    $merchant = 'paypay_merchant_'.Str::random(8);
    $connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_branch_id' => null,
        'owner_scope' => 'hq',
        'environment' => PaymentGatewayEnvironmentEnum::Test,
        'merchant_account_id' => $merchant,
        'is_active' => true,
    ]);

    // The QR capability, not the preauth one — the stamp on the ledger row is
    // what tells an auditor which capability took the money.
    $option = PaymentGatewayOption::factory()->create([
        'provider_id' => $provider->id,
        'code' => PaymentGatewayCatalogSeeder::PAYPAY_QR_OPTION_CODE,
    ]);
    $connectionOption = PaymentGatewayConnectionOption::factory()->create([
        'connection_id' => $connection->id,
        'option_id' => $option->id,
        'verification_state' => 'verified',
        'is_enabled' => true,
    ]);

    $attemptId = (string) Str::uuid();
    $mpid = PayPayQrCodeClient::merchantPaymentIdFor($attemptId);

    $attempt = PaymentAttempt::factory()->create([
        'id' => $attemptId,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'customer_order_id' => $order->id,
        'connection_id' => $connection->id,
        'connection_option_id' => $connectionOption->id,
        'provider' => PaymentGatewayProviderCodeEnum::Paypay->value,
        'environment' => 'test',
        'channel' => PaymentChannelEnum::CustomerWeb->value,
        'state' => PaymentAttemptStateEnum::ProviderPending->value,
        'operation' => 'sale',
        'currency' => 'JPY',
        'amount_minor' => 3000,
        'provider_object_id' => $mpid,
        'version' => 1,
    ]);

    return [
        'order' => $order,
        'attempt' => $attempt,
        'connection' => $connection,
        'option' => $option,
        'mpid' => $mpid,
        'merchant' => $merchant,
    ];
}

/** The notification PayPay sends when a customer finishes paying, signed. */
function issue1232PostNotification(string $merchant, string $mpid, string $status = 'COMPLETED'): TestResponse
{
    $payload = json_encode([
        'id' => 'ppevt_'.Str::random(12),
        'type' => 'paypay.payment.notification',
        'merchant_id' => $merchant,
        'merchantPaymentId' => $mpid,
        'status' => $status,
    ], JSON_THROW_ON_ERROR);

    return test()->call(
        'POST',
        '/api/v1/webhooks/payment/paypay',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAY_SIGNATURE' => hash_hmac('sha256', $payload, ISSUE1232_WEBHOOK_SECRET),
        ],
        $payload,
    );
}

describe('#1232 item 2 — webhook alone settles the order', function () {
    beforeEach(function () {
        config([
            'services.paypay.api_key' => 'pp_key_dummy',
            'services.paypay.api_secret' => 'pp_secret_dummy',
            'services.paypay.webhook_secret' => ISSUE1232_WEBHOOK_SECRET,
        ]);

        issue1232PollIsOff();
    });

    it('takes a signed notification from HTTP to the ledger with the poll switched off', function () {
        $qr = issue1232LiveQr();
        $gateway = issue1232UseGateway(new GatewayPaymentResult(
            PaymentAttemptStateEnum::Succeeded,
            'COMPLETED',
            new ProviderObjectReference($qr['mpid']),
            new Money(3000, 'JPY'),
            summary: new RedactedData(['provider_code' => 'paypay', 'merchant_reference' => $qr['mpid']]),
        ));

        // No Queue::fake(): the job must actually run, because "verified and
        // queued" is not "paid" and a 2xx here has never meant the money landed.
        issue1232PostNotification($qr['merchant'], $qr['mpid'])
            ->assertOk()
            ->assertJson(['received' => true]);

        $inbox = PaymentProviderEvent::query()
            ->where('provider_object_id', $qr['mpid'])
            ->sole();

        // #1232 item 1 bullet 4, verbatim.
        expect($inbox->outcome)->toBe('orchestrator_paypay_attempt_recovered');

        expect($qr['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Succeeded)
            ->and($gateway->retrieveCalls)->toBe(1);

        $row = OrderPayment::query()->where('customer_order_id', $qr['order']->id)->sole();

        // #1232 item 1 bullet 3, verbatim: both stamps, not just the connection.
        expect((string) $row->gateway_connection_id)->toBe((string) $qr['connection']->id)
            ->and((string) $row->gateway_option_id)->toBe((string) $qr['option']->id)
            ->and($row->status)->toBe(PaymentStatusEnum::Succeeded)
            ->and((float) $row->amount)->toBe(3000.0)
            ->and($row->channel)->toBe(PaymentChannelEnum::CustomerWeb->value);

        $order = $qr['order']->fresh();
        expect((float) $order->paid_amount)->toBe(3000.0)
            ->and($order->status)->toBe(CustomerOrderStatusEnum::Closed);
    });

    it('writes one row when the provider retries the same notification', function () {
        // PayPay retries on anything that is not a 2xx, and a shop that ran
        // without a registered webhook until now will get its first traffic in a
        // burst. Two rows for one scan is money invented on the ledger.
        $qr = issue1232LiveQr();
        issue1232UseGateway(new GatewayPaymentResult(
            PaymentAttemptStateEnum::Succeeded,
            'COMPLETED',
            new ProviderObjectReference($qr['mpid']),
            new Money(3000, 'JPY'),
            summary: new RedactedData(['provider_code' => 'paypay']),
        ));

        issue1232PostNotification($qr['merchant'], $qr['mpid'])->assertOk();
        issue1232PostNotification($qr['merchant'], $qr['mpid'])->assertOk();

        expect(OrderPayment::query()->where('customer_order_id', $qr['order']->id)->count())->toBe(1)
            ->and((float) $qr['order']->fresh()->paid_amount)->toBe(3000.0);
    });

    it('refuses a notification whose signature does not match, and books nothing', function () {
        // The negative control for the test above: without it, a green run only
        // proves the route accepts bytes, not that it checks them.
        $qr = issue1232LiveQr();
        issue1232UseGateway(new GatewayPaymentResult(
            PaymentAttemptStateEnum::Succeeded,
            'COMPLETED',
            new ProviderObjectReference($qr['mpid']),
            new Money(3000, 'JPY'),
            summary: new RedactedData(['provider_code' => 'paypay']),
        ));

        $payload = json_encode([
            'id' => 'ppevt_'.Str::random(12),
            'type' => 'paypay.payment.notification',
            'merchant_id' => $qr['merchant'],
            'merchantPaymentId' => $qr['mpid'],
            'status' => 'COMPLETED',
        ], JSON_THROW_ON_ERROR);

        $this->call(
            'POST',
            '/api/v1/webhooks/payment/paypay',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_PAYPAY_SIGNATURE' => hash_hmac('sha256', $payload, 'a-secret-that-is-not-ours'),
            ],
            $payload,
        )->assertStatus(400);

        expect(PaymentProviderEvent::query()->count())->toBe(0)
            ->and(OrderPayment::query()->count())->toBe(0)
            ->and($qr['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::ProviderPending)
            ->and($qr['order']->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);
    });

    it('queues the work rather than doing it inside the request', function () {
        // The provider's timeout is not our processing budget: intake must
        // persist and hand off. If settlement ever moves inline, a slow ledger
        // write turns into a PayPay retry storm on a shop's busiest hour.
        Queue::fake();

        $qr = issue1232LiveQr();
        issue1232UseGateway(new GatewayPaymentResult(
            PaymentAttemptStateEnum::Succeeded,
            'COMPLETED',
            new ProviderObjectReference($qr['mpid']),
            new Money(3000, 'JPY'),
            summary: new RedactedData(['provider_code' => 'paypay']),
        ));

        issue1232PostNotification($qr['merchant'], $qr['mpid'])->assertOk();

        Queue::assertPushed(ProcessPaymentProviderEventJob::class);

        // Nothing settled yet — the 2xx above is a receipt, not a payment.
        expect(OrderPayment::query()->count())->toBe(0);
    });
});

describe('#1232 item 7 — two phones, one order', function () {
    beforeEach(function () {
        config([
            'services.paypay.api_key' => 'a_key',
            'services.paypay.api_secret' => 'a_secret',
            'services.paypay.merchant_id' => '991602796635988897',
            'services.paypay.environment' => 'sandbox',
        ]);
    });

    it('hands both phones the same code while the bill has not moved', function () {
        // The issue assumes the second mint always kills the first phone's code.
        // Since the resume path landed it does not, and that is the difference
        // between "two phones is a hazard" and "two phones is fine": an
        // unchanged bill produces ONE code, so neither screen can go stale.
        $client = Mockery::mock(PayPayQrCodeClient::class)->makePartial();
        $client->shouldReceive('create')->andReturn([
            'code_id' => '04-fake',
            'url' => 'https://qr-stg.sandbox.paypay.ne.jp/fake',
            'deeplink' => null,
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
            'amount' => 3000,
            'currency' => 'JPY',
        ]);
        $client->shouldReceive('delete')->never();
        app()->instance(PayPayQrCodeClient::class, $client);

        $order = issue1232LiveQr()['order'];
        // A fresh order — the fixture's own attempt belongs to the webhook tests.
        PaymentAttempt::query()->where('customer_order_id', $order->id)->delete();

        $service = app(PayPayPaymentService::class);
        $phoneA = $service->createQrCode(orderSnapshot($order));
        $phoneB = $service->createQrCode(orderSnapshot($order));

        expect($phoneB['merchant_payment_id'])->toBe($phoneA['merchant_payment_id']);
        expect(PaymentAttempt::query()->where('customer_order_id', $order->id)->count())->toBe(1);
    });

    it('names the live code on the poll so a superseded phone can tell its own is dead', function () {
        // When the bill DOES move the later mint kills the earlier code, and the
        // first phone's only way to notice is the merchant payment id on its own
        // poll: it holds one string, the poll answers another. Without this the
        // screen keeps a healthy countdown running over a dead QR.
        $client = Mockery::mock(PayPayQrCodeClient::class)->makePartial();
        $client->shouldReceive('create')->andReturnUsing(fn ($connection, $payload) => [
            'code_id' => '04-fake',
            'url' => 'https://qr-stg.sandbox.paypay.ne.jp/fake',
            'deeplink' => null,
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
            'amount' => 3000,
            'currency' => 'JPY',
        ]);
        $client->shouldReceive('delete')->andReturn(true);
        $client->shouldReceive('retrieve')->andReturnUsing(fn ($connection, $mpid) => [
            'status' => 'CREATED',
            'merchant_payment_id' => $mpid,
            'paypay_payment_id' => null,
            'amount' => 2000,
            'currency' => 'JPY',
            'expires_at' => now()->addMinutes(5)->getTimestamp(),
        ]);
        app()->instance(PayPayQrCodeClient::class, $client);

        $order = issue1232LiveQr()['order'];
        PaymentAttempt::query()->where('customer_order_id', $order->id)->delete();

        $service = app(PayPayPaymentService::class);
        $phoneA = $service->createQrCode(orderSnapshot($order));

        // A coupon lands between the two mints: phone B collects ¥2000.
        $order->update(['total_amount' => 2000]);
        $phoneB = $service->createQrCode(orderSnapshot($order));

        expect($phoneB['merchant_payment_id'])->not->toBe($phoneA['merchant_payment_id']);

        // Phone A polls with the code it still shows on screen.
        $status = $service->syncStatus(orderSnapshot($order));

        expect($status['merchant_payment_id'])->toBe($phoneB['merchant_payment_id'])
            ->and($status['merchant_payment_id'])->not->toBe($phoneA['merchant_payment_id']);
    });
});
