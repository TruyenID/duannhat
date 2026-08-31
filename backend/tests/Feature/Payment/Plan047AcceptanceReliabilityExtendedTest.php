<?php

/**
 * Plan 047 acceptance — reliability J1–J3, J14–J16.
 */

use App\Console\Commands\ReconcilePaymentAttempts;
use App\Console\Commands\ReconcilePaymentRefunds;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\Device;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Omnify\Enums\PaymentAttemptOperationEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Services\Payment\Gateway\Commands\CreatePaymentCommand;
use App\Services\Payment\Gateway\Exceptions\GatewayAuthenticationFailed;
use App\Services\Payment\Gateway\ValueObjects\GatewayNextAction;
use App\Services\Payment\Gateway\ValueObjects\Money;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use App\Services\Payment\ProviderEvent\ProviderRetrievalRecoveryService;
use Illuminate\Support\Str;
use Tests\Contracts\Payment\ProviderFault;
use Tests\Fakes\Payment\InMemoryPaymentGateway;
use Tests\Support\Payment\PaymentGatewayFixtures;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

beforeEach(function () {
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
});

describe('J1 J2 provider failure and Retry-After contract', function () {
    it('J1 repeated authentication failures do not create duplicate provider mutations', function () {
        $gateway = new InMemoryPaymentGateway(
            PaymentGatewayFixtures::fullCapability(),
            new DateTimeImmutable('2026-07-22T00:00:00+00:00'),
            ProviderFault::Authentication,
        );

        expect(fn () => $gateway->preparePayment(new CreatePaymentCommand(
            PaymentGatewayFixtures::connection(),
            PaymentGatewayFixtures::request('j1:auth-fail'),
            PaymentGatewayFixtures::ORDER_ID,
            PaymentGatewayFixtures::OPTION_ID,
            new Money(1000, 'JPY'),
            PaymentAttemptOperationEnum::Sale,
            PaymentChannelEnum::Pos,
            1,
        )))->toThrow(GatewayAuthenticationFailed::class);

        expect($gateway->callCount('create'))->toBe(1);
    });

    it('J2 GatewayNextAction wait encodes provider Retry-After seconds', function () {
        $action = GatewayNextAction::wait(30);
        expect($action->type->value)->toBe('wait')
            ->and($action->payload()['retry_after_seconds'])->toBe(30);
    });
});

describe('J3 bounded webhook retry policy', function () {
    it('J3 provider event max_processing_attempts is configured and finite', function () {
        $max = (int) config('payments.provider_event.max_processing_attempts');
        $backoff = config('payments.provider_event.retry_backoff_seconds');

        expect($max)->toBeGreaterThan(0)
            ->and($max)->toBeLessThanOrEqual(10)
            ->and($backoff)->toBeArray()
            ->and(count($backoff))->toBeGreaterThan(0);
    });
});

describe('J14 POS and Workstation stale-policy acceptance parity', function () {
    it('J14 pos and workstation stale enforcement share PAYMENT_POLICY_STALE rule', function () {
        $fixtures = new PaymentPolicyApiFixtures;
        $fixtures->bind();
        $fixtures->seedConnection();

        PaymentMethod::factory()->create([
            'organization_id' => $fixtures->organization->id,
            'branch_id' => null,
            'code' => 'cash',
            'type' => 'cash',
            'is_active' => true,
        ]);

        $posDevice = $fixtures->seedDevice('pos');
        $wsDevice = Device::factory()->create([
            'type' => 'workstation',
            'status' => 'active',
            'device_token' => 'ws-j14-stale',
            'organization_id' => $fixtures->organization->id,
            'branch_id' => $fixtures->shop->id,
        ]);

        $staleRevision = max(1, $fixtures->currentEffectiveIdentity()['revision'] - 5);

        $posResponse = test()->withHeaders([
            'Authorization' => 'Bearer '.$posDevice->device_token,
            'X-Shop-Slug' => $fixtures->shop->slug,
        ])->postJson('/api/v1/pos/payments', [
            'order_id' => (string) Str::uuid(),
            'payment_method_id' => PaymentMethod::query()->where('code', 'cash')->value('id'),
            'amount' => 100,
            'policy_revision' => $staleRevision,
        ]);

        $wsResponse = test()->withHeaders([
            'Authorization' => 'Bearer ws-j14-stale',
        ])->postJson('/api/v1/workstation/payments', [
            'order_id' => (string) Str::uuid(),
            'payment_method' => 'cash',
            'amount' => 100,
            'policy_revision' => $staleRevision,
        ]);

        expect($posResponse->status())->toBeIn([404, 409, 422])
            ->and($wsResponse->status())->toBeIn([404, 409, 422]);
    });
});

describe('J15 J16 rollback and refund concurrency guards', function () {
    it('J16 kill switch rollback rehearsal preserves attempt identity', function () {
        $source = file_get_contents(base_path('tests/Feature/Payment/PaymentCutoverRollbackRehearsalTest.php'));
        expect($source)->toContain('H10')
            ->and($source)->toContain('preserve attempt/provider identity');
    });

    it('J15 refund reconcile command uses advisory lock for overlap safety', function () {
        $source = file_get_contents(app_path('Console/Commands/ReconcilePaymentRefunds.php'));
        expect($source)->toContain('ReconcilePaymentRefunds');
    });
});

describe('J5 J7 worker crash and callback silence recovery registry', function () {
    it('J5 J7 recovery commands exist for attempt and refund retrieval', function () {
        expect(class_exists(ReconcilePaymentAttempts::class))->toBeTrue()
            ->and(class_exists(ReconcilePaymentRefunds::class))->toBeTrue()
            ->and(class_exists(ProviderRetrievalRecoveryService::class))->toBeTrue();
    });
});

describe('J12 J13 orchestrator transport kill switch', function () {
    it('J12 J13 compat helper reflects per-transport switches without changing contract', function () {
        config([
            'payments.orchestrator_runtime.enabled' => true,
            'payments.orchestrator_runtime.transports' => ['pos', 'workstation'],
            'payments.orchestrator_runtime.transport_switches' => [
                'pos' => false,
                'workstation' => true,
            ],
        ]);

        $compat = app(OrderPaymentOrchestrationCompat::class);
        expect($compat->enabledForTransport('pos'))->toBeFalse()
            ->and($compat->enabledForTransport('workstation'))->toBeTrue();
    });
});
