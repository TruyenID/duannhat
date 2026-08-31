<?php

/**
 * plan-054 R13 / #2445 — `payments:sweep-paypay-qr`.
 *
 * The webhook receiver exists, but Live delivery still depends on PayPay
 * registering our URL. Until then — and whenever the customer closes the tab —
 * this sweep is what advances a QR attempt. Every test here is about what
 * happens after the customer's own poll stops: the tab closed, the code
 * lapsed, or — the case that costs money — the customer paid a second before
 * they left.
 *
 * The QR client is faked, so PayPay's answer is the input to each case and no
 * test performs network I/O. Everything below the client is real: the ledger
 * funnel with its five guards, the attempt finalisation, the order settlement.
 * A test that stubbed those would prove the command calls something, not that
 * the money lands.
 */

use App\Console\Commands\SweepStalePayPayQrAttempts;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentGatewayProvider;
use App\Models\PaymentProviderEvent;
use App\Models\ShopOrderSetting;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayEnvironmentEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Payment\Gateway\PayPay\PayPayQrCodeClient;
use App\Services\Payment\Gateway\ValueObjects\GatewayConnectionData;
use App\Services\Payment\ProviderEvent\ProviderEventApplicator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;

uses()->group('payment');

/**
 * A QR client that answers with a scripted PayPay reply.
 *
 * `retrieve` throws rather than returning: the sweep must reach PayPay through
 * `findPayment`, which is the only variant that can tell "nobody scanned it"
 * (HTTP 404) apart from "PayPay is unreachable". A refactor that reaches for
 * the plain read would silently lose that distinction, so it fails loudly here.
 */
function plan054SweepFakeClient(?array $details, ?Throwable $failure = null): PayPayQrCodeClient
{
    $fake = new class($details, $failure) extends PayPayQrCodeClient
    {
        public int $asked = 0;

        public function __construct(
            private readonly ?array $scriptedDetails,
            private readonly ?Throwable $scriptedFailure,
        ) {
            parent::__construct();
        }

        public function findPayment(
            GatewayConnectionData $connection,
            string $merchantPaymentId,
            string $correlationId,
        ): ?array {
            $this->asked++;

            if ($this->scriptedFailure !== null) {
                throw $this->scriptedFailure;
            }

            return $this->scriptedDetails;
        }

        public function retrieve(
            GatewayConnectionData $connection,
            string $merchantPaymentId,
            string $correlationId,
        ): array {
            throw new RuntimeException('The sweep must ask through findPayment().');
        }
    };

    app()->instance(PayPayQrCodeClient::class, $fake);

    return $fake;
}

/** The shape PayPayQrCodeClient::retrieve returns for a scanned code. */
function plan054SweepCompleted(string $mpid, int $minorAmount = 3000, string $currency = 'JPY'): array
{
    return [
        'status' => 'COMPLETED',
        'merchant_payment_id' => $mpid,
        'paypay_payment_id' => 'pp_'.Str::random(10),
        'amount' => $minorAmount,
        'currency' => $currency,
        'expires_at' => null,
    ];
}

/**
 * Any non-COMPLETED answer.
 *
 * `paypay_payment_id` is the discriminator that matters on a `CREATED` code:
 * absent means nobody ever scanned it, present means a scan is in flight.
 */
function plan054SweepStatus(string $mpid, string $status, ?string $paypayPaymentId = null): array
{
    return [
        'status' => $status,
        'merchant_payment_id' => $mpid,
        'paypay_payment_id' => $paypayPaymentId,
        'amount' => null,
        'currency' => null,
        'expires_at' => null,
    ];
}

/**
 * An order with a QR attempt sitting exactly where a closed tab leaves one:
 * open, holding the `tempoqr-` merchant payment id, and old enough that no
 * customer can still be looking at the code.
 *
 * `prepared_at` is the staleness clock — NOT NULL by database default, unlike
 * the nullable `created_at`.
 *
 * @return array{order: CustomerOrder, attempt: PaymentAttempt, connection: PaymentGatewayConnection, mpid: string}
 */
function plan054SweepFixture(
    array $orderOverrides = [],
    array $attemptOverrides = [],
    int $mintedMinutesAgo = 30,
): array {
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

    // payment_gateway_providers.code is unique, so a test building two fixtures
    // shares the one row rather than trying to create a second PayPay provider.
    $provider = PaymentGatewayProvider::query()
        ->where('code', PaymentGatewayProviderCodeEnum::Paypay->value)
        ->first()
        ?? PaymentGatewayProvider::factory()->create([
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
    $mpid = PayPayQrCodeClient::merchantPaymentIdFor($attemptId);

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
        // What a mint actually leaves behind: nothing moves the attempt to
        // provider_pending until a provider answer arrives.
        'state' => PaymentAttemptStateEnum::Prepared->value,
        'operation' => 'sale',
        'currency' => 'JPY',
        'amount_minor' => 3000,
        'provider_object_id' => $mpid,
        'prepared_at' => now()->subMinutes($mintedMinutesAgo),
        'version' => 1,
    ], $attemptOverrides));

    return [
        'order' => $order,
        'attempt' => $attempt,
        'connection' => $connection,
        'mpid' => $mpid,
    ];
}

/** Capture `error` lines without silencing the rest of the orchestration log. */
function plan054SweepSpyOnLog(): MockInterface
{
    $logger = Log::spy();
    $logger->shouldReceive('channel')->andReturnSelf();

    return $logger;
}

/*
|--------------------------------------------------------------------------
| Money that moved
|--------------------------------------------------------------------------
*/

it('books a payment the customer made after they stopped polling', function () {
    // The whole reason this command exists: the tab closed between the scan and
    // the next poll, so nothing in this system had noticed the money.
    $fixture = plan054SweepFixture();
    plan054SweepFakeClient(plan054SweepCompleted($fixture['mpid']));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    $row = OrderPayment::query()->where('customer_order_id', $fixture['order']->id)->sole();

    expect((float) $row->amount)->toBe(3000.0)
        ->and($row->status)->toBe(PaymentStatusEnum::Succeeded)
        ->and($row->reference_no)->toBe($fixture['mpid'])
        // (customer_order_id, idempotency_key) is the only unique index on
        // order_payments — the DB backstop against the customer's own poll
        // writing a second row for the same merchant payment id.
        ->and($row->idempotency_key)->toBe($fixture['mpid'])
        ->and($row->payment_attempt_id)->toBe((string) $fixture['attempt']->id)
        // No drawer collected this, so it stays out of shift reconciliation.
        ->and($row->channel)->toBe(PaymentChannelEnum::CustomerWeb->value);

    $order = $fixture['order']->fresh();
    expect((float) $order->paid_amount)->toBe(3000.0)
        ->and($order->status)->toBe(CustomerOrderStatusEnum::Closed);

    // The leak itself: the attempt must not be left open for the next sweep.
    expect($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Succeeded);
});

it('books what PayPay says it took, never what the attempt asked for', function () {
    // The attempt asked for 3000; PayPay confirms 2500. Booking the attempt's
    // own figure would invent 500 yen of revenue.
    $fixture = plan054SweepFixture();
    plan054SweepFakeClient(plan054SweepCompleted($fixture['mpid'], minorAmount: 2500));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect((float) OrderPayment::query()->sole()->amount)->toBe(2500.0)
        ->and((float) $fixture['order']->fresh()->paid_amount)->toBe(2500.0)
        // Short-paid, so the order stays open for the rest.
        ->and($fixture['order']->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);
});

it('writes one ledger row however many times it runs', function () {
    // The sweep races the customer's own poll by construction — both ask PayPay
    // and both book through the same funnel. Re-running is the cheapest proof
    // that the idempotency probe, not luck, is what keeps it to one row.
    $fixture = plan054SweepFixture();
    plan054SweepFakeClient(plan054SweepCompleted($fixture['mpid']));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    // Re-open the attempt so the second run reconsiders the same code rather
    // than skipping it for being terminal.
    $fixture['attempt']->forceFill(['state' => PaymentAttemptStateEnum::Prepared->value])->save();

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect(OrderPayment::query()->where('customer_order_id', $fixture['order']->id)->count())->toBe(1)
        ->and((float) $fixture['order']->fresh()->paid_amount)->toBe(3000.0);
});

/*
|--------------------------------------------------------------------------
| Money that never moved
|--------------------------------------------------------------------------
*/

it('retires a code that expired unscanned without touching the ledger', function () {
    $fixture = plan054SweepFixture();
    plan054SweepFakeClient(plan054SweepStatus($fixture['mpid'], 'EXPIRED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    $attempt = $fixture['attempt']->fresh();

    // EXPIRED is an ordinary terminal outcome for a QR; only the QR state map
    // knows that. The preauth map would park it as reconciliation_required and
    // send an operator chasing money that never moved.
    expect($attempt->state)->toBe(PaymentAttemptStateEnum::Canceled)
        // PayPay's own answer on the row, not our mint-failure wording.
        ->and($attempt->provider_status)->toBe('EXPIRED')
        // (connection_id, provider_object_id) is the only key a late
        // notification has to find this attempt by.
        ->and($attempt->provider_object_id)->toBe($fixture['mpid']);

    expect(OrderPayment::query()->count())->toBe(0)
        ->and((float) $fixture['order']->fresh()->paid_amount)->toBe(0.0)
        ->and($fixture['order']->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);
});

it('retires a code PayPay has no payment for at all', function () {
    // The ordinary end of an unscanned dynamic QR: PayPay does not report it as
    // a status, it answers 404 for the merchant payment id. Without this case
    // the common outcome would never close and the sweep would achieve nothing.
    $fixture = plan054SweepFixture();
    plan054SweepFakeClient(null);

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Canceled)
        ->and($fixture['attempt']->fresh()->provider_status)->toBe('NOT_FOUND')
        ->and(OrderPayment::query()->count())->toBe(0);
});

it('leaves an attempt alone when the question itself failed', function () {
    // A timeout is not an answer. Retiring on one would make a paid order
    // unbookable, because every path that books a PayPay payment refuses a
    // terminal attempt.
    $fixture = plan054SweepFixture();
    plan054SweepFakeClient(null, new RuntimeException('paypay unreachable'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Prepared)
        ->and(OrderPayment::query()->count())->toBe(0);
});

it('retires a grace-expired code PayPay still calls CREATED with no payment behind it', function () {
    // Verified against the real sandbox: a code nobody scanned answers CREATED
    // INDEFINITELY — 22 minutes after minting, against a ~5 minute code life.
    // There is no EXPIRED status on this endpoint and no 404 either, so age is
    // the only evidence, and without this rule the ordinary outcome (nobody
    // scanned) would never close and the sweep would achieve nothing but a
    // 15-minute heartbeat on the leak it exists to stop.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 22);
    plan054SweepFakeClient(plan054SweepStatus($fixture['mpid'], 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    $attempt = $fixture['attempt']->fresh();

    expect($attempt->state)->toBe(PaymentAttemptStateEnum::Canceled)
        // OUR verdict, not PayPay's word: writing `CREATED` onto a canceled row
        // would record a contradiction and hide that the conclusion was drawn
        // from age rather than from anything the provider said.
        ->and($attempt->provider_status)->toBe('LAPSED_UNSCANNED')
        ->and($attempt->provider_object_id)->toBe($fixture['mpid']);

    expect(OrderPayment::query()->count())->toBe(0)
        ->and((float) $fixture['order']->fresh()->paid_amount)->toBe(0.0)
        ->and($fixture['order']->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);
});

it('leaves a CREATED code alone while a scan is in flight', function () {
    // Same status, opposite meaning. A payment id means PayPay has a payment
    // object for this code — the customer is mid-authorisation and the money is
    // about to move. Retiring here would put the attempt beyond the reach of
    // every path that books a PayPay payment.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 22);
    plan054SweepFakeClient(plan054SweepStatus($fixture['mpid'], 'CREATED', 'pp_scan_in_flight'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Prepared)
        ->and(OrderPayment::query()->count())->toBe(0);
});

it('refuses to retire unscanned codes when the grace stops clearing the code lifetime', function () {
    // Age is only evidence while the margin holds. Narrow it — by tuning the
    // config down, or because PayPay lengthened its code life — and the sweep
    // must stop inferring rather than start guessing, loudly enough that
    // whoever narrowed it finds out before money does.
    config(['payments.paypay_qr.stale_sweep_grace_minutes' => 8]);

    $fixture = plan054SweepFixture(mintedMinutesAgo: 22);
    plan054SweepFakeClient(plan054SweepStatus($fixture['mpid'], 'CREATED'));

    $logger = plan054SweepSpyOnLog();

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Prepared);

    // Twice, not once: MoneyOrchestrationLog writes every money failure to
    // BOTH the payment_orchestration channel and the default one, because
    // only the latter reaches alerting (#1244). Log::spy() with
    // channel()->andReturnSelf() collapses both onto the same spy, so the
    // count is the only visible evidence that the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.paypay] paypay_qr_sweep_grace_too_short'
            && $context['grace_minutes'] === 8
            && $context['code_lifetime_minutes'] === 5)
        ->twice();
});

it('still refuses a payment that lands after the attempt was retired, loudly', function () {
    // Retiring closes the ATTEMPT; it must not write the MONEY off. If a
    // payment somehow arrives afterwards, the notification path has to refuse
    // it with an alarm naming the merchant payment id — a silent ignore here
    // would be the sweep quietly deciding a customer's money never existed.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 22);
    plan054SweepFakeClient(plan054SweepStatus($fixture['mpid'], 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();
    expect($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Canceled);

    $event = PaymentProviderEvent::factory()->create([
        'organization_id' => $fixture['order']->organization_id,
        'connection_id' => $fixture['connection']->id,
        'provider' => PaymentGatewayProviderCodeEnum::Paypay->value,
        'environment' => 'test',
        'state' => PaymentProviderEventStateEnum::Processing->value,
        'provider_event_id' => 'ppevt_'.Str::random(12),
        'event_type' => 'paypay.payment.notification',
        'provider_object_id' => $fixture['mpid'],
        'outcome' => null,
    ]);

    $logger = plan054SweepSpyOnLog();

    expect(app(ProviderEventApplicator::class)->apply((string) $event->id))
        ->toBe('paypay_ignored_terminal');

    // Twice, not once: MoneyOrchestrationLog writes every money failure to
    // BOTH the payment_orchestration channel and the default one, because
    // only the latter reaches alerting (#1244). Log::spy() with
    // channel()->andReturnSelf() collapses both onto the same spy, so the
    // count is the only visible evidence that the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.paypay] paypay_qr_notification_unbookable'
            && $context['merchant_payment_id'] === $fixture['mpid']
            && $context['outcome'] === 'paypay_ignored_terminal')
        ->twice();

    expect(OrderPayment::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Money that arrived somewhere it cannot be booked (T-15 / T-15b)
|--------------------------------------------------------------------------
*/

it('strands a payment for an order that was already settled at the counter', function () {
    // The customer paid cash at the counter while their QR was still live, then
    // scanned it anyway. Recording this would push collected past the total and
    // hide a real overpayment — so the funnel refuses, and the sweep has to say
    // so loudly enough that somebody reverses it in the merchant portal.
    $fixture = plan054SweepFixture(['paid_amount' => 3000]);
    plan054SweepFakeClient(plan054SweepCompleted($fixture['mpid']));

    $logger = plan054SweepSpyOnLog();

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect(OrderPayment::query()->count())->toBe(0);

    // Twice, not once: MoneyOrchestrationLog writes every money failure to
    // BOTH the payment_orchestration channel and the default one, because
    // only the latter reaches alerting (#1244). Log::spy() with
    // channel()->andReturnSelf() collapses both onto the same spy, so the
    // count is the only visible evidence that the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context) use ($fixture): bool {
            return $message === '[payments.stranded] paypay_qr_sweep_stranded'
                && $context['order_id'] === (string) $fixture['order']->id
                && $context['order_code'] === $fixture['order']->order_code
                && $context['stranded_amount'] === 3000.0
                && $context['reason'] === 'overpayment';
        })
        ->twice();

    // PayPay's answer is that the money moved, so that is what the attempt
    // records — leaving it open would re-strand the same yen every tick.
    expect($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Succeeded);
});

it('strands a payment for an order that was voided while the code was live', function () {
    $fixture = plan054SweepFixture(['status' => CustomerOrderStatusEnum::Voided->value]);
    plan054SweepFakeClient(plan054SweepCompleted($fixture['mpid']));

    $logger = plan054SweepSpyOnLog();

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect(OrderPayment::query()->count())->toBe(0);

    // Twice, not once: MoneyOrchestrationLog writes every money failure to
    // BOTH the payment_orchestration channel and the default one, because
    // only the latter reaches alerting (#1244). Log::spy() with
    // channel()->andReturnSelf() collapses both onto the same spy, so the
    // count is the only visible evidence that the mirror happened.
    $logger->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.stranded] paypay_qr_sweep_stranded'
            && $context['order_status'] === CustomerOrderStatusEnum::Voided->value
            && $context['stranded_amount'] === 3000.0
            && $context['reason'] === 'order_not_payable')
        ->twice();
});

/*
|--------------------------------------------------------------------------
| What the sweep must not touch
|--------------------------------------------------------------------------
*/

it('books a young COMPLETED code without waiting for grace (#2445)', function () {
    // Closed-tab money must not wait for grace + schedule interval. Asking
    // PayPay about a 2-minute-old code and booking COMPLETED is safe — the
    // ledger funnel is idempotent with the customer's own poll.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 2);
    $client = plan054SweepFakeClient(plan054SweepCompleted($fixture['mpid']));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($client->asked)->toBe(1)
        ->and($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Succeeded)
        ->and((float) OrderPayment::query()->sole()->amount)->toBe(3000.0);
});

it('never retires a young CREATED code the customer could still be scanning', function () {
    // Grace still gates retirement. A code lives ~5 minutes at PayPay; retiring
    // inside that window would put a mid-scan attempt beyond every booking path.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 2);
    $client = plan054SweepFakeClient(plan054SweepStatus($fixture['mpid'], 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($client->asked)->toBe(1)
        ->and($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Prepared)
        ->and(OrderPayment::query()->count())->toBe(0);
});

it('honours a raised grace only for retirement, not for booking COMPLETED', function () {
    config(['payments.paypay_qr.stale_sweep_grace_minutes' => 120]);

    $fixture = plan054SweepFixture(mintedMinutesAgo: 30);
    $client = plan054SweepFakeClient(plan054SweepCompleted($fixture['mpid']));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($client->asked)->toBe(1)
        ->and($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Succeeded);
});

it('ignores an attempt that is already terminal', function () {
    $fixture = plan054SweepFixture(attemptOverrides: [
        'state' => PaymentAttemptStateEnum::Canceled->value,
    ]);
    $client = plan054SweepFakeClient(plan054SweepCompleted($fixture['mpid']));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($client->asked)->toBe(0);
});

it('never asks the QR endpoint about a reference that is not a QR', function () {
    // A preauth or Stripe reference read through the code endpoint 404s. Worse,
    // a Stripe attempt recovered here would be double-booked: every other
    // adapter writes its ledger row before it could reach this command.
    plan054SweepFixture(attemptOverrides: ['provider_object_id' => 'pi_3PlanZeroFiveFour']);
    plan054SweepFixture(attemptOverrides: [
        'provider' => PaymentGatewayProviderCodeEnum::Stripe->value,
    ]);

    $client = plan054SweepFakeClient(plan054SweepCompleted('tempoqr-unused'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($client->asked)->toBe(0)
        ->and(OrderPayment::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| --dry-run
|--------------------------------------------------------------------------
*/

it('writes nothing on a dry run, whatever PayPay answers', function (array $details) {
    $fixture = plan054SweepFixture();
    $client = plan054SweepFakeClient($details === [] ? null : $details);

    $this->artisan('payments:sweep-paypay-qr', ['--dry-run' => true])->assertSuccessful();

    // Asking IS the point of the preview — it is a read, and the report is
    // worthless without it. What must not happen is any write.
    expect($client->asked)->toBe(1)
        ->and(OrderPayment::query()->count())->toBe(0)
        ->and($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Prepared)
        ->and($fixture['attempt']->fresh()->version)->toBe(1)
        ->and((float) $fixture['order']->fresh()->paid_amount)->toBe(0.0)
        ->and($fixture['order']->fresh()->status)->toBe(CustomerOrderStatusEnum::Open);
})->with([
    'paid at PayPay' => [['status' => 'COMPLETED', 'amount' => 3000, 'currency' => 'JPY']],
    'expired at PayPay' => [['status' => 'EXPIRED', 'amount' => null, 'currency' => null]],
    // The common one: PayPay says CREATED forever and the sweep concludes from
    // age. A preview must not act on its own inference either.
    'lapsed unscanned' => [['status' => 'CREATED', 'amount' => null, 'currency' => null]],
    'no payment at all' => [[]],
]);

it('reports the decision it would have taken', function () {
    $fixture = plan054SweepFixture();
    plan054SweepFakeClient(plan054SweepCompleted($fixture['mpid']));

    $this->artisan('payments:sweep-paypay-qr', ['--dry-run' => true])
        ->expectsOutputToContain('[dry-run] would book 3000 JPY on order='.$fixture['order']->order_code)
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Shape
|--------------------------------------------------------------------------
*/

it('books the ledger before it closes the attempt', function () {
    // Order matters and cannot be observed without killing the process midway:
    // a succeeded attempt is terminal, and every path that books a PayPay
    // payment refuses a terminal attempt. Crash after the ledger write and the
    // next tick re-asks and no-ops; crash after closing the attempt first and
    // the money is unbookable forever. Pinned against the source.
    $reflection = new ReflectionMethod(SweepStalePayPayQrAttempts::class, 'book');
    $source = implode('', array_slice(
        file((string) $reflection->getFileName()),
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));

    expect(strpos($source, 'recordPayPayPaymentByOrderId('))
        ->toBeLessThan(strpos($source, 'settlePayPayQrAttempt('));
});

/*
|--------------------------------------------------------------------------
| #2454 — the batch cap must not hide work
|--------------------------------------------------------------------------
|
| `candidates()` takes the OLDEST `batch_limit` attempts. Once more than that
| are outstanding at once, the newest never enter a tick — and the newest is the
| customer who just scanned. The tick line reports `candidates: <limit>` either
| way, so a shop's payments would drift later and later with nothing saying why.
|
| Measured 2026-08-11: one shop mints ~9 QR/day and a tick usually sees 0
| candidates, so this never fires today. It is pinned for the day it does.
*/

it('SAYS SO when the batch cap hides outstanding attempts', function () {
    config(['payments.paypay_qr.stale_sweep_batch_limit' => 2]);
    $log = plan054SweepSpyOnLog();

    // Three outstanding, cap of two — one is invisible to this tick.
    foreach (range(1, 3) as $i) {
        plan054SweepFixture(mintedMinutesAgo: 30 + $i);
    }
    plan054SweepFakeClient(plan054SweepStatus('anything', 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    // MoneyOrchestrationLog prefixes the tag and mirrors onto two channels,
    // which Log::spy() collapses into one — same shape the grace_too_short
    // assertion above uses.
    $log->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === '[payments.paypay] paypay_qr_sweep_batch_truncated'
            && $context['outstanding'] === 3
            && $context['examined'] === 2
            && $context['skipped'] === 1)
        ->atLeast()->once();
});

it('stays quiet when everything outstanding fits in one tick', function () {
    // The normal state of a working shop. A warning here would be noise that
    // teaches operators to ignore the real one.
    config(['payments.paypay_qr.stale_sweep_batch_limit' => 50]);

    plan054SweepFixture(mintedMinutesAgo: 30);
    plan054SweepFakeClient(plan054SweepStatus('anything', 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')
        ->doesntExpectOutputToContain('Batch cap reached')
        ->assertSuccessful();
});

it('stays quiet when the count merely EQUALS the cap', function () {
    // Exactly at the limit means everything was examined; nothing is hidden.
    config(['payments.paypay_qr.stale_sweep_batch_limit' => 2]);

    foreach (range(1, 2) as $i) {
        plan054SweepFixture(mintedMinutesAgo: 30 + $i);
    }
    plan054SweepFakeClient(plan054SweepStatus('anything', 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')
        ->doesntExpectOutputToContain('Batch cap reached')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| Backoff ladder (#2454) — who gets asked, and how often
|--------------------------------------------------------------------------
|
| The bug these pin: `candidates()` used to take the OLDEST `batch_limit`
| attempts, so once more codes were live than the cap, the newest never entered
| a tick — and the newest is the customer standing at the counter. Reversing
| the order would only move the starvation onto the old ones, which then never
| get retired. The ladder is what has neither failure, and each case below is
| one half of that claim.
|
*/

it('asks about the NEWEST code first when more are due than the cap allows', function () {
    // The acceptance criterion of #2454, stated as the smallest case that can
    // show it: a cap of one, and two codes competing for the slot. Oldest-first
    // picks the 30-minute-old one; the customer who just scanned waits forever.
    config(['payments.paypay_qr.stale_sweep_batch_limit' => 1]);

    $abandoned = plan054SweepFixture(mintedMinutesAgo: 30);
    $justScanned = plan054SweepFixture(mintedMinutesAgo: 0);
    plan054SweepFakeClient(plan054SweepStatus('anything', 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($justScanned['attempt']->fresh()->last_swept_at)->not->toBeNull()
        ->and($abandoned['attempt']->fresh()->last_swept_at)->toBeNull();
});

it('does not ask again about a code it asked about a moment ago', function () {
    // The other half: an attempt on the 2-minute rung carries a 120s interval,
    // so a second tick in the same minute must leave it alone. Without this the
    // ladder would be decoration and every code would still be polled every
    // tick, which is the cost curve #2454 is about.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 3);
    $client = plan054SweepFakeClient(plan054SweepStatus('anything', 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();
    expect($client->asked)->toBe(1);

    $sweptAt = $fixture['attempt']->fresh()->last_swept_at;

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($client->asked)->toBe(1)
        ->and($fixture['attempt']->fresh()->last_swept_at?->equalTo($sweptAt))->toBeTrue();
});

it('asks again once the interval for that rung has elapsed', function () {
    // Deferred, not dropped. The complement of the case above — otherwise
    // "we did not ask" and "we will never ask" are indistinguishable.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 3);
    $client = plan054SweepFakeClient(plan054SweepStatus('anything', 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();
    expect($client->asked)->toBe(1);

    // Reach the far side of the 120s interval without moving the wall clock,
    // so the attempt stays on the same rung and only its due time changes.
    $fixture['attempt']->forceFill(['last_swept_at' => now()->subSeconds(121)])->saveQuietly();

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($client->asked)->toBe(2);
});

it('stamps an attempt it could not ask about, so it cannot hold the hot slot forever', function () {
    // `last_swept_at IS NULL` is the top priority, so an attempt that can never
    // be resolved — no order, no connection, nobody to ask — would occupy a
    // place in the newest rung on EVERY tick and reintroduce the starvation the
    // ladder removes. The column records that we LOOKED, not that anything
    // moved.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 1);
    // Point at a connection row that does not exist — `connection_id` is NOT
    // NULL, so this is the shape an unlinked attempt actually takes.
    $fixture['attempt']->forceFill(['connection_id' => (string) Str::uuid()])->saveQuietly();
    plan054SweepFakeClient(plan054SweepStatus('anything', 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($fixture['attempt']->fresh()->last_swept_at)->not->toBeNull()
        // Still unresolved — stamping is bookkeeping, not a conclusion.
        ->and($fixture['attempt']->fresh()->state)->toBe(PaymentAttemptStateEnum::Prepared);
});

it('leaves the ladder untouched on a dry run', function () {
    // A preview that moved every due time forward would change what the next
    // REAL tick does — the one thing a preview must not do.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 1);
    $client = plan054SweepFakeClient(plan054SweepStatus('anything', 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr', ['--dry-run' => true])->assertSuccessful();

    // It still ASKED — that is the value of the preview — but recorded nothing.
    expect($client->asked)->toBe(1)
        ->and($fixture['attempt']->fresh()->last_swept_at)->toBeNull();
});

it('keeps sweeping the old rungs rather than starving them', function () {
    // The failure mode of simply reversing the order: newest-first never
    // retires the abandoned codes and the candidate set grows without bound.
    // A 30-minute-old code is on the slowest rung, but with room in the cap it
    // is still examined — and, being past grace, retired.
    $fixture = plan054SweepFixture(mintedMinutesAgo: 30);
    plan054SweepFakeClient(plan054SweepStatus('anything', 'CREATED'));

    $this->artisan('payments:sweep-paypay-qr')->assertSuccessful();

    expect($fixture['attempt']->fresh()->last_swept_at)->not->toBeNull()
        ->and($fixture['attempt']->fresh()->state)->not->toBe(PaymentAttemptStateEnum::Prepared);
});
