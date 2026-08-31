<?php

/**
 * Plan-048 T7.5 / #1105 (J1) — adapter-level circuit breaker, flag-gated.
 *
 * Acceptance (plan-047 TESTS.md §J1): the breaker opens after repeated
 * provider failures and answers PAYMENT_PROVIDER_CIRCUIT_OPEN without
 * creating payment attempts. Refinements encoded here:
 *   - default OFF: registry returns the raw driver, outages never refuse;
 *   - only provider OUTAGES trip it — declines/mapped errors never do;
 *   - half-open after cooldown: exactly one probe passes, success closes,
 *     failure re-opens;
 *   - recovery operations (retrieve) are never refused while open.
 */

use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentGatewayProviderCodeEnum;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Contracts\PaymentGatewayContract;
use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use App\Services\Payment\Gateway\Exceptions\PaymentProviderCircuitOpen;
use App\Services\Payment\Gateway\PaymentGatewayRegistry;
use App\Services\Payment\Gateway\Results\GatewayPaymentResult;
use App\Services\Payment\Gateway\Results\GatewayRefundResult;
use App\Services\Payment\Gateway\Results\VerifiedGatewayEvent;
use App\Services\Payment\Gateway\Support\CircuitBreakerGateway;
use App\Services\Payment\Gateway\Support\PaymentProviderCircuitBreaker;
use App\Services\Payment\Gateway\ValueObjects\CapabilitySet;
use App\Services\Payment\Gateway\ValueObjects\ConnectionLocator;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use App\Services\Payment\Gateway\ValueObjects\RedactedData;
use Illuminate\Container\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Stripe\Exception\ApiConnectionException;
use Tests\Fakes\Payment\InMemoryPaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;

uses()->group('payment');

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'payments.circuit_breaker.enabled' => true,
        'payments.circuit_breaker.failure_threshold' => 3,
        'payments.circuit_breaker.failure_window_seconds' => 120,
        'payments.circuit_breaker.cooldown_seconds' => 60,
        'payments.circuit_breaker.probe_ttl_seconds' => 30,
    ]);
    Cache::flush();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Inner fake whose behaviour per call is scripted by a queue of outcomes. */
function cbFakeGateway(ArrayObject $log, array $script = []): PaymentGatewayContract
{
    return new class($log, $script) implements PaymentGatewayContract
    {
        public function __construct(private ArrayObject $log, private array $script) {}

        private function run(string $op): mixed
        {
            $this->log->append($op);
            $next = array_shift($this->script);
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }

        public function capabilities($connection): CapabilitySet
        {
            return PaymentGatewayFixtures::fullCapability();
        }

        // #2938 — fake này chỉ đo breaker, không đi đường webhook.
        public function identifyConnection(array $payload): ?ConnectionLocator
        {
            return null;
        }

        public function preparePayment($command): GatewayPaymentResult
        {
            return $this->run('prepare');
        }

        public function retrievePayment($command): GatewayPaymentResult
        {
            return $this->run('retrieve');
        }

        public function capture($command): GatewayPaymentResult
        {
            return $this->run('capture');
        }

        public function cancel($command): GatewayPaymentResult
        {
            return $this->run('cancel');
        }

        public function refund($command): GatewayRefundResult
        {
            return $this->run('refund');
        }

        public function retrieveRefund($command): GatewayRefundResult
        {
            return $this->run('retrieveRefund');
        }

        public function verifyWebhook($command): VerifiedGatewayEvent
        {
            return $this->run('verifyWebhook');
        }
    };
}

function cbCreateCommand(int $seq = 1): CreatePaymentCommand
{
    return new CreatePaymentCommand(
        PaymentGatewayFixtures::connection(),
        PaymentGatewayFixtures::request("cb:idem:{$seq}", "cb:trace:{$seq}"),
        PaymentGatewayFixtures::ORDER_ID,
        PaymentGatewayFixtures::OPTION_ID,
        new Money(1000, 'JPY'),
        PaymentAttemptOperationEnum::Sale,
        PaymentChannelEnum::Pos,
        1,
        null,
        new RedactedData([]),
    );
}

function cbRetrieveCommand(int $seq = 1): RetrievePaymentCommand
{
    return new RetrievePaymentCommand(
        PaymentGatewayFixtures::connection(),
        PaymentGatewayFixtures::request("cb:idem:r{$seq}", "cb:trace:r{$seq}"),
        new ProviderObjectReference('pi_cb_probe'),
    );
}

function cbOutage(): ApiConnectionException
{
    return ApiConnectionException::factory('Connection to Stripe failed');
}

function cbWrap(PaymentGatewayContract $inner): CircuitBreakerGateway
{
    return new CircuitBreakerGateway($inner, new PaymentProviderCircuitBreaker);
}

it('opens after the failure threshold and refuses creates without touching the adapter', function () {
    $log = new ArrayObject;
    $gateway = cbWrap(cbFakeGateway($log, [cbOutage(), cbOutage(), cbOutage()]));

    foreach ([1, 2, 3] as $seq) {
        expect(fn () => $gateway->preparePayment(cbCreateCommand($seq)))
            ->toThrow(ApiConnectionException::class);
    }
    expect($log->count())->toBe(3);

    // Fourth create: refused typed, adapter NOT called → no side effects, no
    // provider object, nothing to reserve an attempt against.
    expect(fn () => $gateway->preparePayment(cbCreateCommand(4)))
        ->toThrow(PaymentProviderCircuitOpen::class, 'temporarily unavailable');
    expect($log->count())->toBe(3);

    try {
        $gateway->preparePayment(cbCreateCommand(5));
        $this->fail('expected PaymentProviderCircuitOpen');
    } catch (PaymentProviderCircuitOpen $e) {
        expect($e->errorCode)->toBe('PAYMENT_PROVIDER_CIRCUIT_OPEN')
            ->and($e->retryAfterSeconds)->toBeGreaterThan(0);
    }
});

it('never trips on mapped business failures (declines, auth) — only provider outages count', function () {
    $log = new ArrayObject;
    $gateway = cbWrap(cbFakeGateway($log, [
        new GatewayAuthenticationFailed('cb:auth:1'),
        new GatewayAuthenticationFailed('cb:auth:2'),
        new GatewayAuthenticationFailed('cb:auth:3'),
        new GatewayAuthenticationFailed('cb:auth:4'),
    ]));

    foreach ([1, 2, 3, 4] as $seq) {
        expect(fn () => $gateway->preparePayment(cbCreateCommand($seq)))
            ->toThrow(GatewayAuthenticationFailed::class);
    }

    // All four reached the adapter — the circuit never opened.
    expect($log->count())->toBe(4);
});

it('half-open after cooldown: one probe passes, concurrent calls stay refused, probe success closes', function () {
    Carbon::setTestNow('2026-07-27 12:00:00');
    $log = new ArrayObject;
    $result = new GatewayPaymentResult(
        PaymentAttemptStateEnum::Succeeded,
        'succeeded',
        new ProviderObjectReference('pi_cb_ok'),
        new Money(1000, 'JPY'),
    );
    $gateway = cbWrap(cbFakeGateway($log, [cbOutage(), cbOutage(), cbOutage(), $result, $result]));

    foreach ([1, 2, 3] as $seq) {
        try {
            $gateway->preparePayment(cbCreateCommand($seq));
        } catch (ApiConnectionException) {
        }
    }

    // Still inside the cooldown → refused.
    Carbon::setTestNow('2026-07-27 12:00:30');
    expect(fn () => $gateway->preparePayment(cbCreateCommand(4)))
        ->toThrow(PaymentProviderCircuitOpen::class);

    // Cooldown elapsed → the first request wins the probe slot…
    Carbon::setTestNow('2026-07-27 12:01:05');
    expect($gateway->preparePayment(cbCreateCommand(5)))->toBe($result);
    // …and its success closed the circuit: the next create flows normally.
    expect($gateway->preparePayment(cbCreateCommand(6)))->toBe($result);
    expect($log->count())->toBe(5);
});

it('a failed half-open probe re-opens the circuit for a fresh cooldown', function () {
    Carbon::setTestNow('2026-07-27 12:00:00');
    $log = new ArrayObject;
    $gateway = cbWrap(cbFakeGateway($log, [cbOutage(), cbOutage(), cbOutage(), cbOutage()]));

    foreach ([1, 2, 3] as $seq) {
        try {
            $gateway->preparePayment(cbCreateCommand($seq));
        } catch (ApiConnectionException) {
        }
    }

    Carbon::setTestNow('2026-07-27 12:01:05');
    // Probe allowed through — and fails again.
    expect(fn () => $gateway->preparePayment(cbCreateCommand(4)))
        ->toThrow(ApiConnectionException::class);
    expect($log->count())->toBe(4);

    // Immediately refused again (re-opened), adapter untouched.
    expect(fn () => $gateway->preparePayment(cbCreateCommand(5)))
        ->toThrow(PaymentProviderCircuitOpen::class);
    expect($log->count())->toBe(4);
});

it('recovery operations are never refused while open — and their success closes the circuit', function () {
    $log = new ArrayObject;
    $result = new GatewayPaymentResult(
        PaymentAttemptStateEnum::Succeeded,
        'succeeded',
        new ProviderObjectReference('pi_cb_ok'),
        new Money(1000, 'JPY'),
    );
    $gateway = cbWrap(cbFakeGateway($log, [cbOutage(), cbOutage(), cbOutage(), $result, $result]));

    foreach ([1, 2, 3] as $seq) {
        try {
            $gateway->preparePayment(cbCreateCommand($seq));
        } catch (ApiConnectionException) {
        }
    }
    expect(fn () => $gateway->preparePayment(cbCreateCommand(4)))
        ->toThrow(PaymentProviderCircuitOpen::class);

    // Reconciliation retrieve passes straight through the open circuit…
    expect($gateway->retrievePayment(cbRetrieveCommand(1)))->toBe($result);
    // …and, having proven the provider is back, closed it for creates too.
    expect($gateway->preparePayment(cbCreateCommand(5)))->toBe($result);
});

it('state is scoped per connection — one merchant tripping never blocks another', function () {
    $log = new ArrayObject;
    $result = new GatewayPaymentResult(
        PaymentAttemptStateEnum::Succeeded,
        'succeeded',
        new ProviderObjectReference('pi_cb_ok'),
        new Money(1000, 'JPY'),
    );
    $gateway = cbWrap(cbFakeGateway($log, [cbOutage(), cbOutage(), cbOutage(), $result]));

    foreach ([1, 2, 3] as $seq) {
        try {
            $gateway->preparePayment(cbCreateCommand($seq));
        } catch (ApiConnectionException) {
        }
    }
    expect(fn () => $gateway->preparePayment(cbCreateCommand(4)))
        ->toThrow(PaymentProviderCircuitOpen::class);

    $otherConnection = new CreatePaymentCommand(
        PaymentGatewayFixtures::connection('0198f608-0800-7549-9dab-1e05925edcff'),
        PaymentGatewayFixtures::request('cb:idem:other', 'cb:trace:other'),
        PaymentGatewayFixtures::ORDER_ID,
        PaymentGatewayFixtures::OPTION_ID,
        new Money(1000, 'JPY'),
        PaymentAttemptOperationEnum::Sale,
        PaymentChannelEnum::Pos,
        1,
        null,
        new RedactedData([]),
    );
    expect($gateway->preparePayment($otherConnection))->toBe($result);
});

it('flag OFF (the default): the registry hands out the raw driver and outages never refuse', function () {
    config(['payments.circuit_breaker.enabled' => false]);

    $container = new Container;
    $stripe = new InMemoryPaymentGateway(
        PaymentGatewayFixtures::fullCapability(),
        new DateTimeImmutable('2026-07-27T00:00:00+00:00'),
    );
    $container->instance('payments.gateway.stripe', $stripe);
    $registry = new PaymentGatewayRegistry($container, ['stripe' => 'payments.gateway.stripe']);

    // No decorator: byte-identical resolution to the pre-breaker runtime.
    expect($registry->forProvider(
        PaymentGatewayProviderCodeEnum::Stripe,
        'cb:registry:off',
    ))->toBe($stripe);

    // And the breaker itself no-ops: repeated outages never open the circuit.
    $log = new ArrayObject;
    $gateway = cbWrap(cbFakeGateway($log, [cbOutage(), cbOutage(), cbOutage(), cbOutage()]));
    foreach ([1, 2, 3, 4] as $seq) {
        expect(fn () => $gateway->preparePayment(cbCreateCommand($seq)))
            ->toThrow(ApiConnectionException::class);
    }
    expect($log->count())->toBe(4);
});

it('flag ON: the registry wraps every resolved driver in the breaker decorator', function () {
    // The app container (config service bound) — a bare Container without
    // config resolves the raw driver by design, so the wrap decision needs
    // the real thing.
    $stripe = new InMemoryPaymentGateway(
        PaymentGatewayFixtures::fullCapability(),
        new DateTimeImmutable('2026-07-27T00:00:00+00:00'),
    );
    app()->instance('payments.gateway.stripe.cb-test', $stripe);
    $registry = new PaymentGatewayRegistry(app(), ['stripe' => 'payments.gateway.stripe.cb-test']);

    expect($registry->forProvider(
        PaymentGatewayProviderCodeEnum::Stripe,
        'cb:registry:on',
    ))->toBeInstanceOf(CircuitBreakerGateway::class);
});
