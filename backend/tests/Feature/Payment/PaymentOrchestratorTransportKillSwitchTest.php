<?php

/**
 * Plan 047 Gate 7 (T7.3) — per-transport orchestrator kill switch matrix.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Customer\OrderPaymentService;
use App\Services\Payment\Orchestration\OrderPaymentOrchestrationCompat;
use Illuminate\Support\Str;

beforeEach(function () {
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

    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'type' => 'cash',
        'is_active' => true,
    ]);
});

function orchestratorConfig(bool $enabled, array $allowlist, array $switches): void
{
    config([
        'payments.orchestrator_runtime.enabled' => $enabled,
        'payments.orchestrator_runtime.transports' => $allowlist,
        'payments.orchestrator_runtime.transport_switches' => $switches,
    ]);
}

it('routes through orchestrator only when master, allowlist, and transport switch are all enabled', function () {
    orchestratorConfig(true, ['pos'], ['pos' => true, 'kiosk' => false]);

    $compat = app(OrderPaymentOrchestrationCompat::class);

    expect($compat->enabledForTransport('pos'))->toBeTrue()
        ->and($compat->enabledForTransport('kiosk'))->toBeFalse();
});

it('keeps legacy path when global orchestrator runtime is disabled', function () {
    orchestratorConfig(false, ['pos'], ['pos' => true]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 700,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 700,
        'tendered_amount' => 700,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
    ]);

    expect($payment->status->value)->toBe('succeeded');
});

it('blocks orchestrator routing when transport kill switch is off even if allowlisted', function () {
    orchestratorConfig(true, ['pos', 'kiosk'], ['pos' => false, 'kiosk' => true]);

    expect(app(OrderPaymentOrchestrationCompat::class)->enabledForTransport('pos', 'auto_confirm_create'))
        ->toBeFalse();
});

it('does not treat allowlist-only transport as enabled without its kill switch', function () {
    orchestratorConfig(true, ['kiosk'], ['pos' => false, 'kiosk' => true]);

    expect(app(OrderPaymentOrchestrationCompat::class)->enabledForTransport('pos', 'auto_confirm_create'))
        ->toBeFalse();
});

it('matrix: each transport requires its own switch even when listed in the allowlist', function (string $transport) {
    orchestratorConfig(true, [$transport], array_fill_keys(['pos', 'kiosk', 'workstation', 'customer_web'], false));

    expect(app(OrderPaymentOrchestrationCompat::class)->enabledForTransport($transport))->toBeFalse();

    config(['payments.orchestrator_runtime.transport_switches.'.$transport => true]);

    expect(app(OrderPaymentOrchestrationCompat::class)->enabledForTransport($transport))->toBeTrue();
})->with([
    'pos',
    'kiosk',
    'workstation',
    'customer_web',
]);

it('creates pos payments through orchestrator only when the pos transport switch is on', function () {
    orchestratorConfig(true, ['pos'], ['pos' => true]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 900,
        'paid_amount' => 0,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 900,
        'tendered_amount' => 900,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
        'idempotency_key' => 'kill-switch-pos-on',
    ]);

    expect($payment->status->value)->toBe('succeeded')
        ->and((float) $order->fresh()->paid_amount)->toBe(900.0);
});

it('falls back to legacy create when pos transport switch is off despite master runtime', function () {
    orchestratorConfig(true, ['pos'], ['pos' => false]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 650,
        'paid_amount' => 0,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 650,
        'tendered_amount' => 650,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
        'idempotency_key' => 'kill-switch-pos-off',
    ]);

    expect($payment->status->value)->toBe('succeeded')
        ->and((float) $order->fresh()->paid_amount)->toBe(650.0);
});
