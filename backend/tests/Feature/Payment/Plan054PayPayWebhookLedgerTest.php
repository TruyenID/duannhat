<?php

/**
 * plan-054 M4c — the PayPay webhook must reach the LEDGER, not just the attempt.
 *
 * Before this milestone a PayPay notification flipped `payment_attempts` to
 * succeeded, stamped the inbox row `orchestrator_paypay_attempt_recovered`, and
 * stopped. No `order_payments` row was written, so the customer had paid and the
 * order stayed open with the money recorded nowhere. Stripe survives the same
 * applicator because it has a SECOND webhook route that writes the ledger;
 * PayPay has only the inbox.
 *
 * Every test here therefore drives the money through the APPLICATOR — the path a
 * real notification takes — and never calls recordPayPayPayment directly. A test
 * that calls the recorder proves the recorder works (Plan054RecordPayPayPaymentTest
 * already does); only the path proves the recorder is reached.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Notification;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use App\Models\Role;
use App\Models\ShopOrderSetting;
use App\Models\User;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\DomainMutation\MutationContext;
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
use App\Services\Payment\Orchestration\Commands\FinalizePaymentCommand;
use App\Services\Payment\Orchestration\Contracts\PaymentMutationFacade;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses()->group('payment');

/** The container key the registry resolves the stub through. */
const PLAN054_M4C_GATEWAY = 'tests.plan054-m4c.gateway';

/**
 * Retrieval-only gateway stub.
 *
 * The in-memory fake answers `not_found` for any reference it did not itself
 * mint, which is precisely the case here: the QR was minted by production code
 * paths this test does not run. Everything except retrievePayment throws, so a
 * future refactor that reaches for another gateway call fails loudly rather than
 * silently taking a different route to the ledger.
 */
function plan054M4cGateway(GatewayPaymentResult $result): PaymentGatewayContract
{
    return new class($result) implements PaymentGatewayContract
    {
        public function __construct(private readonly GatewayPaymentResult $result) {}

        public function capabilities(GatewayConnectionData $connection): CapabilitySet
        {
            throw new RuntimeException('unused');
        }

        /**
         * #2938 — uỷ quyền cho adapter THẬT: phân giải connection chạy trước
         * khi xác minh chữ ký, nên nó phải trả lời y như production. Ném ở đây
         * (như các method còn lại) sẽ làm mọi webhook đi qua stub này thành 5xx.
         *
         * @param  array<string, mixed>  $payload
         */
        public function identifyConnection(array $payload): ?ConnectionLocator
        {
            return (new PayPayPaymentGateway)->identifyConnection($payload);
        }

        public function preparePayment(CreatePaymentCommand $command): GatewayPaymentResult
        {
            throw new RuntimeException('unused');
        }

        public function retrievePayment(RetrievePaymentCommand $command): GatewayPaymentResult
        {
            return $this->result;
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

        public function verifyWebhook(VerifyWebhookCommand $command): VerifiedGatewayEvent
        {
            throw new RuntimeException('unused');
        }
    };
}

/**
 * Point the `paypay` (or any) driver slot at the stub above.
 *
 * The registry is a singleton built from config at resolve time, so both the
 * config swap and the forgetInstance are load-bearing.
 */
function plan054M4cUseGateway(GatewayPaymentResult $result, string $provider = 'paypay'): void
{
    app()->bind(PLAN054_M4C_GATEWAY, fn (): PaymentGatewayContract => plan054M4cGateway($result));
    config(['payments.gateway_drivers.'.$provider => PLAN054_M4C_GATEWAY]);
    app()->forgetInstance(PaymentGatewayRegistry::class);
}

/**
 * A branch/order/attempt/inbox-event set that mirrors a live QR: the attempt
 * carries the `tempoqr-` merchant payment id as its provider_object_id, which is
 * both the webhook's match key and the ledger row's idempotency key.
 *
 * @return array{order: CustomerOrder, attempt: PaymentAttempt, event: PaymentProviderEvent, mpid: string}
 */
function plan054M4cFixture(array $orderOverrides = [], array $attemptOverrides = [], ?string $mpid = null): array
{
    $consoleOrganizationId = (string) Str::uuid();

    $organization = Organization::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $brand = Brand::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
    ]);
    $branch = Branch::factory()->create([
        'console_organization_id' => $consoleOrganizationId,
        'console_brand_id' => $brand->console_brand_id,
        'currency' => 'JPY',
    ]);

    $order = CustomerOrder::factory()->create(array_merge([
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'order_type' => 'takeaway',
        'status' => CustomerOrderStatusEnum::Open->value,
        'total_amount' => 3000,
        'paid_amount' => 0,
    ], $orderOverrides));

    // The currency guard reads the order's priced currency the same way the
    // Stripe funnel does — shop_order_settings, not branches.currency.
    ShopOrderSetting::factory()->create([
        'branch_id' => $branch->id,
        'organization_id' => $organization->id,
        'currency_code' => 'JPY',
    ]);

    $provider = PaymentGatewayProvider::factory()->create([
        'code' => PaymentGatewayProviderCodeEnum::Paypay,
        'is_active' => true,
    ]);
    $connection = PaymentGatewayConnection::factory()->create([
        'provider_id' => $provider->id,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'owner_branch_id' => null,
        'owner_scope' => 'hq',
        'environment' => PaymentGatewayEnvironmentEnum::Test,
        'merchant_account_id' => 'paypay_merchant_'.Str::random(8),
        'is_active' => true,
    ]);

    $attemptId = (string) Str::uuid();
    $mpid ??= PayPayQrCodeClient::merchantPaymentIdFor($attemptId);

    $attempt = PaymentAttempt::factory()->create(array_merge([
        'id' => $attemptId,
        'organization_id' => $organization->id,
        'brand_id' => $brand->id,
        'branch_id' => $branch->id,
        'customer_order_id' => $order->id,
        'connection_id' => $connection->id,
        'provider' => PaymentGatewayProviderCodeEnum::Paypay->value,
        'environment' => 'test',
        'channel' => PaymentChannelEnum::CustomerWeb->value,
        'state' => PaymentAttemptStateEnum::ProviderPending->value,
        'operation' => 'sale',
        'currency' => 'JPY',
        'amount_minor' => 3000,
        'provider_object_id' => $mpid,
        'version' => 1,
    ], $attemptOverrides));

    $event = PaymentProviderEvent::factory()->create([
        'organization_id' => $organization->id,
        'connection_id' => $connection->id,
        'provider' => PaymentGatewayProviderCodeEnum::Paypay->value,
        'environment' => 'test',
        'state' => PaymentProviderEventStateEnum::Processing->value,
        'provider_event_id' => 'ppevt_'.Str::random(12),
        'event_type' => 'paypay.payment.notification',
        'provider_object_id' => $mpid,
        'outcome' => null,
    ]);

    return ['order' => $order, 'attempt' => $attempt, 'event' => $event, 'mpid' => $mpid];
}

function plan054M4cSucceeded(string $mpid, int $minorAmount = 3000, string $currency = 'JPY'): GatewayPaymentResult
{
    return new GatewayPaymentResult(
        PaymentAttemptStateEnum::Succeeded,
        'COMPLETED',
        new ProviderObjectReference($mpid),
        new Money($minorAmount, $currency),
        summary: new RedactedData(['provider_code' => 'paypay', 'merchant_reference' => $mpid]),
    );
}

/** Capture `error` lines without silencing the rest of the orchestration log. */
function plan054M4cSpyOnLog(): MockInterface
{
    $logger = Log::spy();
    $logger->shouldReceive('channel')->andReturnSelf();

    return $logger;
}

it('books the money on the ledger and settles the order from the webhook path', function () {
    $fixture = plan054M4cFixture();
    plan054M4cUseGateway(plan054M4cSucceeded($fixture['mpid']));

    $outcome = app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id);

    expect($outcome)->toBe('orchestrator_paypay_attempt_recovered');

    // The attempt flipping is what USED to be the whole story.
    expect($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Succeeded);

    $row = OrderPayment::query()->where('customer_order_id', $fixture['order']->id)->sole();

    expect((float) $row->amount)->toBe(3000.0)
        ->and($row->status)->toBe(PaymentStatusEnum::Succeeded)
        ->and($row->reference_no)->toBe($fixture['mpid'])
        // (customer_order_id, idempotency_key) is the only unique index on
        // order_payments — this stamping is the DB backstop against the poll
        // path writing a second row for the same merchant payment id.
        ->and($row->idempotency_key)->toBe($fixture['mpid'])
        ->and($row->payment_attempt_id)->toBe((string) $fixture['attempt']->id)
        // No drawer collected this, so it must stay out of shift reconciliation.
        ->and($row->channel)->toBe(PaymentChannelEnum::CustomerWeb->value);

    $order = $fixture['order']->fresh();
    expect((float) $order->paid_amount)->toBe(3000.0)
        ->and($order->status)->toBe(CustomerOrderStatusEnum::Closed);
});

it('books what PayPay says it took, never what the attempt asked for', function () {
    // The attempt asked for 3000; PayPay confirms 2500. Booking amount_minor
    // here would invent 500 yen of revenue — the reason the ledger call lives
    // where $evidence is still in scope rather than in the applicator, which
    // only ever gets a bool back.
    $fixture = plan054M4cFixture();
    plan054M4cUseGateway(plan054M4cSucceeded($fixture['mpid'], minorAmount: 2500));

    app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id);

    $row = OrderPayment::query()->where('customer_order_id', $fixture['order']->id)->sole();

    expect((float) $row->amount)->toBe(2500.0)
        ->and((float) $fixture['order']->fresh()->paid_amount)->toBe(2500.0)
        // Short-paid, so the order stays open for the rest.
        ->and($fixture['order']->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);
});

it('is idempotent when the same notification is applied twice', function () {
    $fixture = plan054M4cFixture();
    plan054M4cUseGateway(plan054M4cSucceeded($fixture['mpid']));

    app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id);

    // A redelivered notification lands on a fresh inbox row; the attempt is now
    // terminal, so this asserts the ledger guard rather than the applicator's.
    app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id);

    expect(OrderPayment::query()->where('customer_order_id', $fixture['order']->id)->count())->toBe(1)
        ->and((float) $fixture['order']->fresh()->paid_amount)->toBe(3000.0);
});

it('strands money for an order that died while its QR was live, loudly and without a row', function () {
    $fixture = plan054M4cFixture(['status' => CustomerOrderStatusEnum::Voided->value]);
    plan054M4cUseGateway(plan054M4cSucceeded($fixture['mpid']));

    $logger = plan054M4cSpyOnLog();

    app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id);

    // No ledger row: the money cannot be booked against a voided order. It is
    // NOT auto-refunded either — PayPay refunds are deliberately unwired
    // (plan-054 D5), so the log is the hand-off to a human with a portal.
    expect(OrderPayment::query()->where('customer_order_id', $fixture['order']->id)->count())->toBe(0);

    // Twice, not once: MoneyOrchestrationLog writes every money failure to
    // BOTH the payment_orchestration channel and the default one, because
    // only the latter reaches alerting (#1244). Log::spy() with
    // channel()->andReturnSelf() collapses both onto the same spy, so the
    // count is the only visible evidence that the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) use ($fixture): bool {
            return $message === '[payments.stranded] paypay_stranded_payment'
                && $context['order_id'] === (string) $fixture['order']->id
                && $context['merchant_payment_id'] === $fixture['mpid']
                && $context['stranded_amount'] === 3000.0
                && $context['reason'] === 'order_not_payable';
        })
        ->twice();
});

it('strands a charge in a currency the order was not priced in', function () {
    $fixture = plan054M4cFixture();
    plan054M4cUseGateway(plan054M4cSucceeded($fixture['mpid'], minorAmount: 3000, currency: 'USD'));

    $logger = plan054M4cSpyOnLog();

    app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id);

    expect(OrderPayment::query()->where('customer_order_id', $fixture['order']->id)->count())->toBe(0);

    // Twice, not once: MoneyOrchestrationLog writes every money failure to
    // BOTH the payment_orchestration channel and the default one, because
    // only the latter reaches alerting (#1244). Log::spy() with
    // channel()->andReturnSelf() collapses both onto the same spy, so the
    // count is the only visible evidence that the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.stranded] paypay_stranded_payment'
            && $context['reason'] === 'currency_mismatch'
            // USD is a two-decimal currency: 3000 minor units is 30.00, not 3000.
            && $context['stranded_amount'] === 30.0)
        ->twice();
});

it('leaves the ledger alone for a non-PayPay attempt recovered through the same service', function () {
    // Every other adapter wrote its order_payments row before reaching recovery.
    // Without the provider gate this would double-book all of them.
    $fixture = plan054M4cFixture(attemptOverrides: [
        'provider' => PaymentGatewayProviderCodeEnum::Stripe->value,
    ]);
    plan054M4cUseGateway(plan054M4cSucceeded($fixture['mpid']));

    app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id);

    expect($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Succeeded)
        ->and(OrderPayment::query()->where('customer_order_id', $fixture['order']->id)->count())->toBe(0);
});

it('alarms when a QR notification finds no attempt at all', function () {
    $fixture = plan054M4cFixture();
    $fixture['attempt']->forceFill(['provider_object_id' => 'tempoqr-somethingelse'])->save();

    $logger = plan054M4cSpyOnLog();

    expect(app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id))
        // The outcome string is persisted and read elsewhere — the alarm is
        // added beside it, never in place of it.
        ->toBe('paypay_no_matching_attempt');

    // Twice, not once: MoneyOrchestrationLog writes every money failure to
    // BOTH the payment_orchestration channel and the default one, because
    // only the latter reaches alerting (#1244). Log::spy() with
    // channel()->andReturnSelf() collapses both onto the same spy, so the
    // count is the only visible evidence that the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.paypay] paypay_qr_notification_unbookable'
            && $context['merchant_payment_id'] === $fixture['mpid']
            && $context['outcome'] === 'paypay_no_matching_attempt')
        ->twice();
});

it('alarms when a QR notification finds an attempt that is already terminal', function () {
    // The recovery path is the ONLY thing that books a PayPay QR payment, and it
    // does not run for a terminal attempt — so this is the shape of "the customer
    // paid and nothing will ever ledger it".
    $fixture = plan054M4cFixture(attemptOverrides: [
        'state' => PaymentAttemptStateEnum::Expired->value,
    ]);

    $logger = plan054M4cSpyOnLog();

    expect(app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id))
        ->toBe('paypay_ignored_terminal');

    // Twice, not once: MoneyOrchestrationLog writes every money failure to
    // BOTH the payment_orchestration channel and the default one, because
    // only the latter reaches alerting (#1244). Log::spy() with
    // channel()->andReturnSelf() collapses both onto the same spy, so the
    // count is the only visible evidence that the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.paypay] paypay_qr_notification_unbookable'
            && $context['outcome'] === 'paypay_ignored_terminal')
        ->twice();
});

it('stays quiet for a non-QR merchant payment id, which has no customer waiting', function () {
    // A preauth/in-store reference is a different lifecycle with its own
    // reconciliation; alarming on it would train operators to ignore the alarm.
    $fixture = plan054M4cFixture(mpid: 'preauth_reference_not_a_qr');
    $fixture['attempt']->forceFill(['provider_object_id' => 'preauth_other'])->save();

    $logger = plan054M4cSpyOnLog();

    expect(app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id))
        ->toBe('paypay_no_matching_attempt');

    $logger->shouldNotHaveReceived('error');
});

it('keeps the merchant payment id when finalizing on evidence that carries no reference', function () {
    // (connection_id, provider_object_id) is the only key a later notification
    // has to find this attempt by. finalizeAttempt used to write
    // `$evidence->payment?->value` with no fallback, so one failed finalize
    // NULLed the column and made every subsequent webhook unmatchable.
    $fixture = plan054M4cFixture();

    app(PaymentMutationFacade::class)->finalize(new FinalizePaymentCommand(
        new MutationContext(
            (string) $fixture['attempt']->organization_id,
            null,
            'plan054-m4c:finalize-without-reference',
            (string) $fixture['attempt']->id,
            1,
        ),
        (string) $fixture['attempt']->id,
        new GatewayPaymentResult(
            PaymentAttemptStateEnum::Failed,
            'FAILED',
            summary: new RedactedData(['provider_code' => 'paypay']),
        ),
    ));

    expect($fixture['attempt']->fresh()->provider_object_id)->toBe($fixture['mpid']);
});

// =========================================================================
//  #2697 — cảnh báo phải TỚI ĐƯỢC một con người, không chỉ vào file log
// =========================================================================

/**
 * Lỗ 3 của #2694: `paypay_qr_notification_unbookable` nổ hai lần trên production
 * ngày 12/08 và sinh **0 thông báo**. Dòng log ở lại (alerting của DevOps khớp
 * theo tiền tố `[payments.paypay]`); cái được thêm là một hàng inbox có NGƯỜI
 * NHẬN. Khẳng định "số người nhận > 0" là phần có giá: một slug vai sai vẫn tạo
 * ra hàng `notifications` nhưng không gửi cho ai, và im lặng mãi mãi (#2451).
 */
function plan054M4cGrantOrgAdmin(PaymentAttempt $attempt): User
{
    $role = Role::firstOrCreate(
        ['slug' => 'org-admin'],
        [
            'id' => (string) Str::uuid(),
            'console_organization_id' => (string) $attempt->organization_id,
            'name' => 'Org Admin',
            'level' => 100,
        ],
    );

    $user = User::factory()->create();

    DB::table('role_user_pivots')->insert([
        'user_id' => $user->id,
        'role_id' => $role->id,
        'organization_id' => (string) $attempt->organization_id,
        'branch_id' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

it('#2697 — một QR không sổ được sinh thông báo tới người nhận KHÁC RỖNG', function () {
    $fixture = plan054M4cFixture(attemptOverrides: [
        'state' => PaymentAttemptStateEnum::Expired->value,
    ]);
    $admin = plan054M4cGrantOrgAdmin($fixture['attempt']);

    expect(app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id))
        ->toBe('paypay_ignored_terminal');

    $notification = Notification::query()
        ->where('type', 'payment.paypay_qr_unbookable')
        ->latest('id')
        ->first();

    expect($notification)->not->toBeNull();

    $recipientIds = $notification->recipients()->pluck('recipient_id')->all();

    expect(count($recipientIds))->toBeGreaterThan(0)
        ->and($recipientIds)->toContain($admin->id)
        ->and($notification->params['merchant_payment_id'] ?? null)->toBe($fixture['mpid'])
        ->and($notification->params['outcome'] ?? null)->toBe('paypay_ignored_terminal')
        ->and($notification->priority->value)->toBe('urgent');
});

it('#2697 — một QR sổ được bình thường KHÔNG sinh thông báo nào', function () {
    $fixture = plan054M4cFixture();
    plan054M4cUseGateway(plan054M4cSucceeded($fixture['mpid']));
    plan054M4cGrantOrgAdmin($fixture['attempt']);

    app(ProviderEventApplicator::class)->apply((string) $fixture['event']->id);

    expect(Notification::query()->where('type', 'payment.paypay_qr_unbookable')->count())->toBe(0);
});
