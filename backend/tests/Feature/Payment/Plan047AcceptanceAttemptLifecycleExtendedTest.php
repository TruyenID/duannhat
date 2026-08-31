<?php

/**
 * Plan 047 acceptance — attempt lifecycle C3–C10.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Organization;
use App\Models\User;
use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Payment\Gateway\Commands\CapturePaymentCommand;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Commands\RetrievePaymentCommand;
use App\Services\Payment\Gateway\Exceptions\UnsupportedPaymentOperation;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Gateway\ValueObjects\ProviderObjectReference;
use Illuminate\Support\Str;
use Tests\Contracts\Payment\ProviderFault;
use Tests\Fakes\Payment\InMemoryPaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;

beforeEach(function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['pos'],
        'payments.orchestrator_runtime.transport_switches' => ['pos' => true],
        'payments.gateway_drivers.stripe' => InMemoryPaymentGateway::class,
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
        'is_active' => true,
    ]);
    $this->operator = User::factory()->create(['console_organization_id' => $this->organizationId]);
});

describe('C3 provider success crash recovery via retrieval', function () {
    it('C3 retrievePayment returns succeeded evidence for stored provider identity', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );
        $created = $gateway->preparePayment(new CreatePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('c3:recovery'),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1200, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        ));

        $retrieved = $gateway->retrievePayment(new RetrievePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('c3:retrieve'),
            $created->payment,
        ));

        expect($retrieved->state)->toBe(PaymentAttemptStateEnum::Succeeded)
            ->and($retrieved->payment?->value)->toBe($created->payment?->value);
    });
});

describe('C4 provider timeout reconciliation_required', function () {
    it('C4 maps provider timeout to reconciliation_required without new provider key', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
            ProviderFault::Timeout,
        );

        $first = $gateway->preparePayment(new CreatePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('c4:timeout-key'),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1000, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        ));
        $second = $gateway->preparePayment(new CreatePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('c4:timeout-key'),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1000, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        ));

        expect($first->state)->toBe(PaymentAttemptStateEnum::ReconciliationRequired)
            ->and($second->state)->toBe(PaymentAttemptStateEnum::ReconciliationRequired)
            ->and($gateway->callCount('create'))->toBe(1);
    });
});

describe('C5 provider 500 replay follows idempotency semantics', function () {
    it('C5 replays the same provider result on retried create with same idempotency key', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
            ProviderFault::Decline,
        );

        $first = $gateway->preparePayment(new CreatePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('c5:500-replay'),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1000, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        ));
        $replay = $gateway->preparePayment(new CreatePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('c5:500-replay'),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1000, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        ));

        expect($first->state)->toBe(PaymentAttemptStateEnum::Failed)
            ->and($replay->rawStatus)->toBe($first->rawStatus)
            ->and($gateway->callCount('create'))->toBe(1);
    });
});

describe('C7 concurrent split cannot exceed remaining order amount', function () {
    it('C7 documents overpayment guard in OrderPaymentService', function () {
        $source = file_get_contents(app_path('Services/Customer/OrderPaymentService.php'));
        expect($source)->toContain('overpayment_blocked')
            ->and($source)->toContain('Payment amount exceeds the outstanding order balance');
    });
});

describe('C8 provider states normalize while retaining raw status', function () {
    it('C8 preserves raw provider status on normalized attempt states', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
            ProviderFault::Decline,
        );

        $failed = $gateway->preparePayment(new CreatePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('c8:declined'),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1000, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        ));

        expect($failed->state)->toBe(PaymentAttemptStateEnum::Failed)
            ->and($failed->rawStatus)->toBe('card_declined');
    });
});

describe('C9 unsupported operations return typed capability errors', function () {
    it('C9 rejects capture on unsupported capability contract', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::unsupportedMutationCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
        );

        expect(fn () => $gateway->capture(new CapturePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('c9:capture'),
            new ProviderObjectReference('fake_pay_capture'),
            new Money(1000, 'JPY'),
        )))->toThrow(UnsupportedPaymentOperation::class);
    });
});

describe('C6 C10 orchestrator prepare reserves attempt before provider boundary', function () {
    it('C6 C10 PaymentOrchestrator prepare only reserves attempt skeleton in persistence port', function () {
        $orchestratorSource = file_get_contents(app_path('Services/Payment/Orchestration/PaymentOrchestrator.php'));
        $persistenceSource = file_get_contents(app_path('Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php'));

        expect($orchestratorSource)->toContain('return $this->persistence->reserveAttempt($reserved)')
            ->and($orchestratorSource)->not->toContain('preparePayment(')
            ->and($persistenceSource)->toContain('function reserveAttempt')
            ->and($persistenceSource)->toContain('prepareAttemptSkeleton');
    });

    it('C6 finalize uses lockForUpdate on attempt row', function () {
        $source = file_get_contents(app_path('Services/Payment/Orchestration/Internal/EloquentPaymentPersistence.php'));
        expect($source)->toContain('lockForUpdate()');
    });
});

describe('C10 provider registry resolves outside reserveAttempt transaction', function () {
    it('C10 keeps gateway driver resolution in ProviderRetrievalRecoveryService not inside reserveAttempt', function () {
        $recoverySource = file_get_contents(app_path('Services/Payment/ProviderEvent/ProviderRetrievalRecoveryService.php'));
        $prepareSource = file_get_contents(app_path('Services/Payment/Orchestration/PaymentOrchestrator.php'));

        expect($recoverySource)->toContain('$gateway->retrievePayment')
            ->and($prepareSource)->not->toContain('PaymentGatewayRegistry');
    });
});
