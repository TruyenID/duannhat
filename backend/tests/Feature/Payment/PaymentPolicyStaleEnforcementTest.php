<?php

use App\Models\OrderPayment;
use App\Models\PaymentAttempt;
use App\Models\PaymentGatewayConnection;
use App\Models\PaymentMethod;
use App\Models\Till;
use App\Models\TillSession;
use App\Omnify\Enums\PaymentAttemptStateEnum;
use App\Omnify\Enums\PaymentChannelEnum;
use App\Omnify\Enums\PaymentConnectionOwnerScopeEnum;
use App\Omnify\Enums\PaymentStatusEnum;
use App\Omnify\Enums\TillSessionStatusEnum;
use App\Services\Customer\OrderPaymentService;
use App\Services\Payment\Policy\Admin\PaymentPolicyEvaluationService;
use App\Services\Payment\Policy\Enums\BranchManagementModel;
use Illuminate\Support\Str;
use Tests\Support\Payment\PaymentPolicyApiFixtures;

beforeEach(function () {
    $this->fixtures = new PaymentPolicyApiFixtures(shopSlug: 'policy-transport-'.Str::lower(Str::random(6)));
    $this->fixtures->bind();
    $this->actingAs($this->fixtures->manager);
    grantOrgAccess($this->fixtures->manager, (string) $this->fixtures->organization->id);

    $this->fixtures->seedConnection();
    $this->fixtures->publishInitialPolicyRevision();
    $this->policyIdentity = $this->fixtures->currentEffectiveIdentity();
    $this->cash = $this->fixtures->seedCashPaymentMethod();
});

describe('F3 stale policy enforcement', function () {
    it('F3 accepts an unchanged-safe stale revision when shop-effective identity is unchanged', function () {
        $staleRevision = $this->policyIdentity['revision'];
        $order = $this->fixtures->seedCheckoutOrder();
        $evaluation = app(PaymentPolicyEvaluationService::class);

        $evaluation->updateShopOption($this->fixtures->shop, $this->fixtures->option, [
            'preference' => 'disabled',
            'change_reason' => 'lane closed temporarily',
        ]);
        $evaluation->updateShopOption($this->fixtures->shop, $this->fixtures->option, [
            'preference' => 'inherit',
            'change_reason' => 'lane reopened',
        ]);

        expect($this->fixtures->currentEffectiveIdentity()['revision'])->toBeGreaterThan($staleRevision);

        $this->postJson("{$this->fixtures->shopBase()}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cash->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $staleRevision,
        ])->assertCreated();
    });

    it('F3 rejects an unsafe stale revision after the option is disabled with PAYMENT_POLICY_STALE', function () {
        $staleRevision = $this->policyIdentity['revision'];
        $order = $this->fixtures->seedCheckoutOrder();

        $this->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [
            'preference' => 'disabled',
            'change_reason' => 'cash lane closed',
        ])->assertOk();

        $response = $this->postJson("{$this->fixtures->shopBase()}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cash->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $staleRevision,
        ])->assertStatus(422);

        expect($response->json('code'))->toBe('PAYMENT_POLICY_STALE')
            ->and($response->json('action'))->toBe('refresh_payment_options')
            ->and($response->json('details.current_revision'))->toBeGreaterThan($staleRevision)
            ->and(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(0);
    });

    it('F3 rejects kiosk reconnect payments when owner-scoped connection changed', function () {
        $staleRevision = $this->policyIdentity['revision'];
        $order = $this->fixtures->seedCheckoutOrder();
        $device = $this->fixtures->seedKioskDevice();

        $this->fixtures->switchManagementModel(BranchManagementModel::FranchiseOwned);
        PaymentGatewayConnection::query()
            ->where('organization_id', $this->fixtures->organization->id)
            ->delete();

        $this->fixtures->seedConnection([
            'owner_scope' => PaymentConnectionOwnerScopeEnum::Franchise->value,
            'merchant_account_id' => 'acct_franchise_changed',
        ]);
        $this->fixtures->publishInitialPolicyRevision();

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/kiosk/payments', [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 1000,
            'tendered_amount' => 1000,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $staleRevision,
        ])->assertStatus(422);

        expect($response->json('code'))->toBeIn(['PAYMENT_POLICY_STALE', 'PAYMENT_OPTION_DISABLED'])
            ->and(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(0);
    });
});

describe('F8 empty effective options', function () {
    it('F8 rejects shop payments when the effective option list is empty', function () {
        $order = $this->fixtures->seedCheckoutOrder();

        $this->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [
            'preference' => 'disabled',
        ])->assertOk();

        $effective = $this->getJson("{$this->fixtures->shopBase()}/effective-payment-options")
            ->assertOk()
            ->json('data.options');

        expect(collect($effective)->where('effective', true))->toHaveCount(0);

        $response = $this->postJson("{$this->fixtures->shopBase()}/orders/{$order->id}/payments", [
            'payment_method_id' => $this->cash->id,
            'amount' => 1000,
            'tendered_amount' => 1000,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $this->fixtures->currentEffectiveIdentity()['revision'],
        ])->assertStatus(422);

        expect($response->json('code'))->toBe('PAYMENT_OPTION_DISABLED')
            ->and(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(0);
    });
});

describe('F9 in-flight attempt snapshot', function () {
    it('F9 finalizes a prepared attempt against its frozen revision N connection after revision N+1 disables the option', function () {
        config([
            'payments.orchestrator_runtime.enabled' => true,
            'payments.orchestrator_runtime.transports' => ['pos'],
            'payments.orchestrator_runtime.transport_switches' => [
                'pos' => true,
                'kiosk' => false,
                'workstation' => false,
                'customer_web' => false,
            ],
        ]);

        $order = $this->fixtures->seedCheckoutOrder(600);
        $transfer = PaymentMethod::factory()->transfer()->create([
            'organization_id' => $this->fixtures->organization->id,
            'branch_id' => $this->fixtures->shop->id,
            'is_active' => true,
        ]);

        $revisionRecord = $this->fixtures->publishInitialPolicyRevision();
        $frozenConnectionId = $this->policyIdentity['connection_id'];
        $frozenRevision = (int) $revisionRecord->revision;
        $connectionOptionId = PaymentGatewayConnection::query()
            ->find($frozenConnectionId)
            ?->paymentGatewayConnectionOptions()
            ->value('id');

        $attempt = PaymentAttempt::factory()->create([
            'organization_id' => $this->fixtures->organization->id,
            'brand_id' => $this->fixtures->brand->id,
            'branch_id' => $this->fixtures->shop->id,
            'customer_order_id' => $order->id,
            'connection_id' => $frozenConnectionId,
            'connection_option_id' => $connectionOptionId,
            'policy_revision_id' => $revisionRecord->id,
            'state' => PaymentAttemptStateEnum::Prepared->value,
            'channel' => PaymentChannelEnum::Pos->value,
            'amount_minor' => 600,
            'currency' => 'JPY',
            'version' => 1,
        ]);

        $payment = app(OrderPaymentService::class)->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $transfer->id,
            'amount' => 600,
            'received_by_id' => $this->fixtures->manager->id,
            'organization_id' => $this->fixtures->organization->id,
            'brand_id' => $this->fixtures->brand->id,
            'branch_id' => $this->fixtures->shop->id,
            'orchestrator_transport' => 'pos',
        ]);
        $payment->update(['payment_attempt_id' => $attempt->id]);

        $this->patchJson("{$this->fixtures->shopBase()}/payment-options/{$this->fixtures->option->id}", [
            'preference' => 'disabled',
        ])->assertOk();

        expect($this->fixtures->currentEffectiveIdentity()['revision'])->toBeGreaterThan($frozenRevision);

        $confirmed = app(OrderPaymentService::class)->confirm($payment->fresh());

        expect($confirmed->status)->toBe(PaymentStatusEnum::Succeeded)
            ->and((string) $attempt->fresh()->connection_id)->toBe($frozenConnectionId)
            ->and($attempt->fresh()->state)->toBe(PaymentAttemptStateEnum::Succeeded);
    });
});

describe('F10 split refund till attribution convergence', function () {
    it('F10 keeps till_session_id and split metadata on workstation sync-up payments', function () {
        $order = $this->fixtures->seedCheckoutOrder(2000);
        $device = $this->fixtures->seedWorkstationDevice();
        $till = Till::factory()->create(['branch_id' => $this->fixtures->shop->id]);
        $session = TillSession::factory()->create([
            'till_id' => $till->id,
            'branch_id' => $this->fixtures->shop->id,
            'organization_id' => $this->fixtures->organization->id,
            'status' => TillSessionStatusEnum::Open->value,
        ]);
        $till->update(['current_session_id' => $session->id]);

        $this->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => 'ws-split-1',
        ])->postJson('/api/v1/workstation/payments', [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 1000,
            'till_session_id' => $session->id,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $this->policyIdentity['revision'],
        ])->assertCreated();

        $this->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => 'ws-split-2',
        ])->postJson('/api/v1/workstation/payments', [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 1000,
            'till_session_id' => $session->id,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $this->policyIdentity['revision'],
        ])->assertCreated();

        expect(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(2);
        $payments = OrderPayment::query()
            ->where('customer_order_id', $order->id)
            ->orderBy('created_at')
            ->get();

        expect($payments->every(fn (OrderPayment $payment): bool => (string) $payment->till_session_id === (string) $session->id))->toBeTrue();
        $first = $payments->first();
        $refund = app(OrderPaymentService::class)->refund($first, ['amount' => 500]);

        expect($refund->status)->toBe(PaymentStatusEnum::Succeeded)
            ->and((float) $refund->amount)->toBe(-500.0)
            ->and((string) $refund->till_session_id)->toBe((string) $session->id);
    });
});

describe('F4 offline replay idempotency soak', function () {
    it('F4 soak disconnect queued payment duplicate sync converges to one cloud payment', function () {
        $order = $this->fixtures->seedCheckoutOrder(1500);
        $device = $this->fixtures->seedWorkstationDevice();
        $idempotencyKey = 'soak-offline-replay-'.Str::uuid();

        $payload = [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 1500,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $this->policyIdentity['revision'],
        ];

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/workstation/payments', $payload)->assertCreated();

        $second = $this->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/workstation/payments', $payload)->assertCreated();

        expect($first->json('data.id'))->toBe($second->json('data.id'))
            ->and(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1)
            ->and(OrderPayment::where('idempotency_key', $idempotencyKey)->count())->toBe(1);
    });

    it('F4 soak policy disable then stale resubmit blocks a second payment while first idempotent replay still converges', function () {
        $order = $this->fixtures->seedCheckoutOrder(2000);
        $device = $this->fixtures->seedWorkstationDevice();
        $staleRevision = $this->policyIdentity['revision'];
        $idempotencyKey = 'soak-stale-block-'.Str::uuid();

        $accepted = $this->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/workstation/payments', [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 900,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $staleRevision,
        ])->assertCreated();

        app(PaymentPolicyEvaluationService::class)->updateShopOption(
            $this->fixtures->shop,
            $this->fixtures->option,
            ['preference' => 'disabled'],
        );

        $this->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/workstation/payments', [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 900,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $staleRevision,
        ])->assertCreated()
            ->assertJsonPath('data.id', $accepted->json('data.id'));

        $blocked = $this->withHeaders([
            'Authorization' => "Bearer {$device->device_token}",
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson('/api/v1/workstation/payments', [
            'order_id' => $order->id,
            'payment_method' => 'cash',
            'amount' => 900,
            'gateway_option_id' => $this->policyIdentity['option_id'],
            'gateway_connection_id' => $this->policyIdentity['connection_id'],
            'policy_revision' => $staleRevision,
        ])->assertStatus(422);

        expect($blocked->json('code'))->toBe('PAYMENT_POLICY_STALE')
            ->and(OrderPayment::where('customer_order_id', $order->id)->count())->toBe(1);
    });
});
