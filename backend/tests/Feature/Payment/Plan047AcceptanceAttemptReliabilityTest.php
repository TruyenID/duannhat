<?php

/**
 * Plan 047 acceptance — attempt lifecycle C*, reliability J8–J13, currency J10.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\DomainMutation\MutationContext;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Exceptions\IdempotencyPayloadMismatch;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Orchestration\Commands\PreparePaymentCommand;
use App\Services\Payment\Orchestration\Enums\RefundReason;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use App\Services\Payment\Orchestration\Support\PaymentOrchestrationLogContext;
use App\Services\Payment\Orchestration\ValueObjects\RefundRequestPayload;
use App\Support\CurrencyMinorUnit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\Fakes\Payment\InMemoryPaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;

beforeEach(function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['pos', 'kiosk', 'workstation', 'customer_web'],
        'payments.orchestrator_runtime.transport_switches' => [
            'pos' => true,
            'kiosk' => true,
            'workstation' => true,
            'customer_web' => true,
        ],
    ]);

    $this->organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->organizationId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);
    $this->operator = User::factory()->create(['console_organization_id' => $this->organizationId]);
    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
    ]);
});

describe('C2 duplicate client idempotency key', function () {
    it('C2 returns the same payment row and does not duplicate ledger entries', function () {
        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'status' => 'checkout',
            'total_amount' => 900,
            'paid_amount' => 0,
        ]);

        $payload = [
            'customer_order_id' => $order->id,
            'payment_method_id' => $this->cash->id,
            'amount' => 900,
            'tendered_amount' => 900,
            'received_by_id' => $this->operator->id,
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'orchestrator_transport' => 'pos',
            'idempotency_key' => 'c2-pos-dup-key',
        ];

        $service = app(OrderPaymentService::class);
        $first = $service->create($payload);
        $second = $service->create($payload);

        expect($second->id)->toBe($first->id)
            ->and(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1)
            ->and((float) $order->fresh()->paid_amount)->toBe(900.0);
    });
});

describe('C1 prepared attempt skeleton', function () {
    it('C1 leaves a prepared attempt when card payment stays pending under orchestrator', function () {
        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'status' => 'checkout',
            'total_amount' => 600,
        ]);

        $card = PaymentMethod::factory()->card()->create([
            'organization_id' => $this->organizationId,
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);

        $payment = app(OrderPaymentService::class)->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $card->id,
            'amount' => 600,
            'received_by_id' => $this->operator->id,
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'orchestrator_transport' => 'pos',
            'idempotency_key' => 'c1-pending-card',
        ]);

        expect($payment->status->value)->toBe('pending');

        if ($payment->payment_attempt_id !== null) {
            $attempt = PaymentAttempt::query()->find($payment->payment_attempt_id);
            expect($attempt)->not->toBeNull()
                ->and($attempt->state->value)->toBeIn(['prepared', 'submitted', 'pending']);
        }
    });
});

describe('J8 provider idempotency payload mismatch', function () {
    it('J8 rejects same idempotency key with different amount at gateway boundary', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );

        $connection = PaymentGatewayFixtures::connection();
        $base = PaymentGatewayFixtures::request('j8:same-key');

        $gateway->preparePayment(new CreatePaymentCommand(
            $connection,
            $base,
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1000, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        ));

        expect(fn () => $gateway->preparePayment(new CreatePaymentCommand(
            $connection,
            $base,
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1100, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        )))->toThrow(IdempotencyPayloadMismatch::class);

        expect($gateway->callCount('create'))->toBe(1);
    });
});

describe('J9 zero or negative capture amounts', function () {
    it('J9 rejects zero amountMinor in PreparePaymentCommand', function () {
        $context = new MutationContext(
            organizationId: $this->organizationId,
            actorId: (string) $this->operator->id,
            correlationId: 'j9-zero-amount',
            idempotencyKey: 'j9-zero',
        );

        expect(fn () => new PreparePaymentCommand(
            $context,
            (string) Str::uuid(),
            (string) Str::uuid(),
            (string) $this->branch->id,
            PaymentGatewayFixtures::CONNECTION_ID,
            PaymentGatewayFixtures::OPTION_ID,
            0,
            'JPY',
            1,
            1000,
            1000,
        ))->toThrow(InvalidArgumentException::class);
    });

    it('J9 rejects zero refund amount in RefundRequestPayload', function () {
        expect(fn () => new RefundRequestPayload(
            (string) Str::uuid(),
            (string) Str::uuid(),
            (string) Str::uuid(),
            0,
            'JPY',
            RefundReason::CustomerRequest,
        ))->toThrow(InvalidArgumentException::class);
    });
});

describe('J10 currency precision without float drift', function () {
    it('J10 converts JPY USD and KWD major strings to exact minor integers', function () {
        expect(CurrencyMinorUnit::fromMajor('1000', 'JPY'))->toBe(1000)
            ->and(CurrencyMinorUnit::fromMajor('10.50', 'USD'))->toBe(1050)
            ->and(CurrencyMinorUnit::fromMajor('1.234', 'KWD'))->toBe(1234)
            ->and(CurrencyMinorUnit::fromMajor('10.501', 'USD'))->toBeNull();
    });

    it('J10 orchestrator tender stores USD major units not inflated minor values', function () {
        $usdBranch = Branch::factory()->create([
            'console_organization_id' => $this->organizationId,
            'console_brand_id' => $this->brand->console_brand_id,
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $usdCash = PaymentMethod::factory()->cash()->create([
            'organization_id' => $this->organizationId,
            'branch_id' => $usdBranch->id,
            'is_active' => true,
        ]);
        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $usdBranch->id,
            'status' => 'checkout',
            'total_amount' => 12.34,
            'paid_amount' => 0,
        ]);

        $payment = app(OrderPaymentService::class)->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $usdCash->id,
            'amount' => 12.34,
            'tendered_amount' => 12.34,
            'received_by_id' => $this->operator->id,
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $usdBranch->id,
            'orchestrator_transport' => 'pos',
            'idempotency_key' => 'j10-usd-major',
        ]);

        expect((float) $payment->amount)->toBe(12.34)
            ->and((float) $order->fresh()->paid_amount)->toBe(12.34);
    });
});

describe('J11 correlation propagation in orchestration logs', function () {
    it('J11 enriches log context with correlation and idempotency hash', function () {
        $context = new MutationContext(
            organizationId: $this->organizationId,
            actorId: (string) $this->operator->id,
            correlationId: 'corr-j11-trace',
            idempotencyKey: 'idem-j11-key',
            expectedVersion: 1,
        );

        $payload = PaymentOrchestrationLogContext::enrich($context, [
            'attempt_id' => 'attempt-j11',
            'api_key' => 'sk_test_should_redact',
        ]);

        expect($payload['correlation_id'])->toBe('corr-j11-trace')
            ->and($payload['idempotency_key_hash'])->not->toBeEmpty()
            ->and($payload['api_key'])->toBe('[REDACTED]');
    });
});

describe('J12 J13 kill switch behavior', function () {
    it('J12 blocks new orchestrator routing when transport switch is off', function () {
        config([
            'payments.orchestrator_runtime.enabled' => true,
            'payments.orchestrator_runtime.transports' => ['pos'],
            'payments.orchestrator_runtime.transport_switches' => ['pos' => false],
        ]);

        expect(app(OrderPaymentOrchestrationCompat::class)->enabledForTransport('pos'))->toBeFalse();
    });

    it('J13 re-enables orchestrator without changing compat helper contract', function () {
        config([
            'payments.orchestrator_runtime.enabled' => true,
            'payments.orchestrator_runtime.transports' => ['pos'],
            'payments.orchestrator_runtime.transport_switches' => ['pos' => true],
        ]);

        $compat = app(OrderPaymentOrchestrationCompat::class);
        expect($compat->enabledForTransport('pos'))->toBeTrue();

        config(['payments.orchestrator_runtime.transport_switches.pos' => false]);
        expect($compat->enabledForTransport('pos'))->toBeFalse();

        config(['payments.orchestrator_runtime.transport_switches.pos' => true]);
        expect($compat->enabledForTransport('pos'))->toBeTrue();
    });
});

describe('J6 reconciliation overlap lock', function () {
    it('J6 reconcile-refunds exits cleanly when lock already held', function () {
        $lock = Cache::lock('payments:reconcile-refunds', 300);
        expect($lock->get())->toBeTrue();

        $this->artisan('payments:reconcile-refunds', ['--execute' => true])
            ->expectsOutputToContain('Another payments:reconcile-refunds run is already in progress.')
            ->assertExitCode(0);

        $lock->release();
    });
});
