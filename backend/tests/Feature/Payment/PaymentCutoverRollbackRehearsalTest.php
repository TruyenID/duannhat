<?php

use App\Jobs\Payment\ProcessPaymentProviderEventJob;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentMethod;
use App\Models\PaymentProviderEvent;
use App\Models\User;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentProviderEventStateEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use App\Services\Payment\ProviderEvent\ProviderEventInboxService;
use App\Services\Payment\ProviderEvent\ProviderEventProcessor;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stripe\PaymentIntent;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

function cutoverOrchestratorConfig(bool $enabled, array $allowlist, array $switches): void
{
    config([
        'payments.orchestrator_runtime.enabled' => $enabled,
        'payments.orchestrator_runtime.transports' => $allowlist,
        'payments.orchestrator_runtime.transport_switches' => $switches,
    ]);
}

/** @return array<string, bool> */
function cutoverTransportSwitches(bool $pos = false, bool $kiosk = false, bool $workstation = false, bool $customerWeb = false): array
{
    return [
        'pos' => $pos,
        'kiosk' => $kiosk,
        'workstation' => $workstation,
        'customer_web' => $customerWeb,
    ];
}

/**
 * Plan 047 Gate 7 (T7.5) — rollback rehearsal tests (H10).
 *
 * Exercises the orchestrator kill switch (`payments.orchestrator_runtime`) at
 * crash boundaries: provider success, webhook backlog, policy publish, and
 * offline replay must preserve attempt/provider identity with zero ledger drift.
 */
beforeEach(function () {
    config([
        'services.stripe.key' => 'pk_test_dummy',
        'services.stripe.secret' => 'sk_test_dummy_secret_for_tests',
        'services.stripe.webhook_secret' => 'whsec_test_secret_xyz',
        'services.stripe.currency' => 'jpy',
    ]);

    $this->organizationId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'JPY',
        'is_active' => true,
    ]);

    $this->operator = User::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    $this->makeStripeEvent = function (string $type, array $dataObject, string $secret = 'whsec_test_secret_xyz'): array {
        $payload = json_encode([
            'id' => 'evt_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'created' => time(),
            'data' => ['object' => $dataObject],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return [
            'payload' => $payload,
            'header' => "t={$timestamp},v1={$signature}",
            'event_id' => json_decode($payload, true)['id'],
        ];
    };

    $this->postWebhook = fn (string $payload, string $signature) => $this->call(
        'POST',
        '/api/v1/customer/stripe/webhook',
        [],
        [],
        [],
        ['HTTP_STRIPE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );

    $this->processInboxBacklogInReceivedOrder = function (): void {
        $inbox = app(ProviderEventInboxService::class);
        $processor = app(ProviderEventProcessor::class);

        $rows = PaymentProviderEvent::query()
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if (in_array($row->state, [
                PaymentProviderEventStateEnum::Succeeded,
                PaymentProviderEventStateEnum::OperatorResolved,
            ], true)) {
                continue;
            }

            (new ProcessPaymentProviderEventJob((string) $row->id))->handle($inbox, $processor);
        }
    };
});

describe('H10 rollback rehearsal', function () {
    it('H10 kill switch OFF after provider success keeps attempts recoverable without duplicate ledger', function () {
        cutoverOrchestratorConfig(true, ['customer_web'], cutoverTransportSwitches(customerWeb: true));

        $intentId = 'pi_h10_kill_switch_recovery';
        $order = CustomerOrder::factory()->create([
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'status' => 'checkout',
            'total_amount' => 1100,
            'paid_amount' => 0,
            'stripe_payment_intent_id' => $intentId,
        ]);

        $compat = app(OrderPaymentOrchestrationCompat::class);
        $attemptId = $compat->prepareStripePaymentIntent($order, $intentId, 1100, 'JPY', 'full');
        expect($attemptId)->not->toBeNull();

        app(OrderPaymentService::class)->recordStripeWebhookPayment(
            $order,
            $intentId,
            1100,
            'full',
            ['flow' => 'full'],
            $attemptId,
        );

        $compat->finalizeStripePayment(
            PaymentAttempt::query()->findOrFail($attemptId),
            PaymentIntent::constructFrom([
                'id' => $intentId,
                'object' => 'payment_intent',
                'amount' => 1100,
                'currency' => 'jpy',
                'status' => 'succeeded',
            ]),
        );

        $attemptBefore = PaymentAttempt::query()->findOrFail($attemptId);
        $ledgerCountBefore = OrderPayment::query()->where('customer_order_id', $order->id)->count();

        expect($attemptBefore->state)->toBe(PaymentAttemptStateEnum::Succeeded)
            ->and($attemptBefore->provider_object_id)->toBe($intentId)
            ->and($ledgerCountBefore)->toBe(1);

        cutoverOrchestratorConfig(false, ['customer_web'], cutoverTransportSwitches(customerWeb: true));

        $compat->finalizeStripePayment(
            $attemptBefore->fresh(),
            PaymentIntent::constructFrom([
                'id' => $intentId,
                'object' => 'payment_intent',
                'amount' => 1100,
                'currency' => 'jpy',
                'status' => 'succeeded',
            ]),
        );

        $wasRecordedAgain = app(OrderPaymentService::class)->recordStripeWebhookPayment(
            $order->fresh(),
            $intentId,
            1100,
            'full',
            ['flow' => 'full'],
            $attemptId,
        );

        $attemptAfter = PaymentAttempt::query()->findOrFail($attemptId);

        expect($wasRecordedAgain)->toBeFalse()
            ->and((string) $attemptAfter->id)->toBe((string) $attemptId)
            ->and($attemptAfter->provider_object_id)->toBe($intentId)
            ->and(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(1)
            ->and(OrderPayment::query()->where('reference_no', $intentId)->count())->toBe(1);
    });

    it('H10 webhook backlog during cutover preserves inbox ordering and finishes with one ledger row', function () {
        cutoverOrchestratorConfig(true, ['customer_web'], cutoverTransportSwitches(customerWeb: true));

        $intentId = 'pi_h10_inbox_backlog';
        CustomerOrder::factory()->create([
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'stripe_payment_intent_id' => $intentId,
            'total_amount' => 950,
            'paid_amount' => 0,
            'status' => CustomerOrderStatusEnum::Open->value,
        ]);

        Queue::fake();

        $created = ($this->makeStripeEvent)('payment_intent.created', [
            'object' => 'payment_intent',
            'id' => $intentId,
        ]);

        usleep(1000);

        $succeeded = ($this->makeStripeEvent)('payment_intent.succeeded', [
            'object' => 'payment_intent',
            'id' => $intentId,
            'amount' => 950,
            'currency' => 'jpy',
            'status' => 'succeeded',
        ]);

        ($this->postWebhook)($created['payload'], $created['header'])->assertOk();
        ($this->postWebhook)($succeeded['payload'], $succeeded['header'])->assertOk();

        Queue::assertPushed(ProcessPaymentProviderEventJob::class, 2);

        $orderedInbox = PaymentProviderEvent::query()
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        expect($orderedInbox)->toHaveCount(2)
            ->and($orderedInbox->first()?->event_type)->toBe('payment_intent.created')
            ->and($orderedInbox->last()?->event_type)->toBe('payment_intent.succeeded');

        cutoverOrchestratorConfig(false, ['customer_web'], cutoverTransportSwitches(customerWeb: true));

        ($this->processInboxBacklogInReceivedOrder)();

        $processed = PaymentProviderEvent::query()
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        expect($processed->first()?->outcome)->toBe('ignored_unsupported')
            ->and($processed->last()?->state)->toBe(PaymentProviderEventStateEnum::Succeeded)
            ->and($processed->last()?->outcome)->toBe('applied')
            ->and(OrderPayment::query()->where('reference_no', $intentId)->count())->toBe(1)
            ->and(CustomerOrder::query()->where('stripe_payment_intent_id', $intentId)->value('status'))
            ->toBe(CustomerOrderStatusEnum::Closed);
    });

    it('H10 policy publish mid-flight keeps in-flight attempt snapshot immutable after rollback', function () {
        cutoverOrchestratorConfig(true, ['pos'], cutoverTransportSwitches(pos: true));

        $fixtures = new PaymentPolicyApiFixtures(shopSlug: 'h10-policy-'.Str::lower(Str::random(6)));
        $fixtures->bind();
        $fixtures->seedConnection();
        $fixtures->publishInitialPolicyRevision();
        $policyIdentity = $fixtures->currentEffectiveIdentity();

        $order = $fixtures->seedCheckoutOrder(700);
        $transfer = PaymentMethod::factory()->transfer()->create([
            'organization_id' => $fixtures->organization->id,
            'branch_id' => $fixtures->shop->id,
            'is_active' => true,
        ]);

        $revisionRecord = $fixtures->publishInitialPolicyRevision();
        $frozenConnectionId = $policyIdentity['connection_id'];
        $frozenRevision = (int) $revisionRecord->revision;
        $connectionOptionId = PaymentGatewayConnection::query()
            ->find($frozenConnectionId)
            ?->paymentGatewayConnectionOptions()
            ->value('id');

        $attempt = PaymentAttempt::factory()->create([
            'organization_id' => $fixtures->organization->id,
            'brand_id' => $fixtures->brand->id,
            'branch_id' => $fixtures->shop->id,
            'customer_order_id' => $order->id,
            'connection_id' => $frozenConnectionId,
            'connection_option_id' => $connectionOptionId,
            'policy_revision_id' => $revisionRecord->id,
            'state' => PaymentAttemptStateEnum::Prepared->value,
            'channel' => PaymentChannelEnum::Pos->value,
            'amount_minor' => 700,
            'currency' => 'JPY',
            'version' => 1,
        ]);

        $payment = app(OrderPaymentService::class)->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $transfer->id,
            'amount' => 700,
            'received_by_id' => $fixtures->manager->id,
            'organization_id' => $fixtures->organization->id,
            'brand_id' => $fixtures->brand->id,
            'branch_id' => $fixtures->shop->id,
            'orchestrator_transport' => 'pos',
        ]);
        $payment->update(['payment_attempt_id' => $attempt->id]);

        test()->actingAs($fixtures->manager);
        grantOrgAccess($fixtures->manager, (string) $fixtures->organization->id);

        test()->patchJson("{$fixtures->shopBase()}/payment-options/{$fixtures->option->id}", [
            'preference' => 'disabled',
        ])->assertOk();

        expect($fixtures->currentEffectiveIdentity()['revision'])->toBeGreaterThan($frozenRevision);

        cutoverOrchestratorConfig(false, ['pos'], cutoverTransportSwitches(pos: true));

        $confirmed = app(OrderPaymentService::class)->confirm($payment->fresh());

        expect($confirmed->status)->toBe(PaymentStatusEnum::Succeeded)
            ->and((string) $attempt->fresh()->connection_id)->toBe($frozenConnectionId)
            ->and((string) $attempt->fresh()->policy_revision_id)->toBe((string) $revisionRecord->id)
            ->and(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(1);
    });

    it('H10 offline queued payment replay after rollback converges idempotently with one ledger row', function () {
        cutoverOrchestratorConfig(true, ['workstation'], cutoverTransportSwitches(workstation: true));

        $fixtures = new PaymentPolicyApiFixtures(shopSlug: 'h10-offline-'.Str::lower(Str::random(6)));
        $fixtures->bind();
        $fixtures->seedConnection();
        $fixtures->publishInitialPolicyRevision();
        $fixtures->seedCashPaymentMethod();
        $policyIdentity = $fixtures->currentEffectiveIdentity();
        $order = $fixtures->seedCheckoutOrder(1300);
        $device = $fixtures->seedWorkstationDevice();
        $idempotencyKey = 'h10-offline-replay-'.Str::uuid();

        $payload = [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 1300,
            'gateway_option_id' => $policyIdentity['option_id'],
            'gateway_connection_id' => $policyIdentity['connection_id'],
            'policy_revision' => $policyIdentity['revision'],
        ];

        $first = test()->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/workstation/payments', $payload)->assertCreated();

        cutoverOrchestratorConfig(false, ['workstation'], cutoverTransportSwitches(workstation: true));

        $replay = test()->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/workstation/payments', $payload)->assertCreated();

        expect($first->json('data.id'))->toBe($replay->json('data.id'))
            ->and(OrderPayment::query()->where('customer_order_id', $order->id)->count())->toBe(1)
            ->and(OrderPayment::query()->where('idempotency_key', $idempotencyKey)->count())->toBe(1)
            ->and((float) $order->fresh()->paid_amount)->toBe(1300.0);
    });
});
