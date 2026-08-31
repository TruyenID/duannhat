<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Support\Str;

/**
 * Plan 047 Gate 3 — recordTender must store MAJOR-unit amounts.
 *
 * The orchestrator tender command carries MINOR units; order_payments.* are
 * MAJOR-unit decimal(15,2) columns. Writing the minor value straight in
 * inflated a 2-decimal currency 100x once the orchestrator runtime is enabled.
 * The existing coverage only used JPY (0-decimal, minor==major), which masked
 * it — this exercises the same orchestrator path in USD.
 */
beforeEach(function () {
    config([
        'payments.orchestrator_runtime.enabled' => true,
        'payments.orchestrator_runtime.transports' => ['pos'],
    ]);

    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'currency' => 'USD',
        'is_active' => true,
    ]);
    $this->operator = User::factory()->create(['console_organization_id' => $this->orgId]);
    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'type' => 'cash',
        'is_active' => true,
    ]);
});

it('records a USD cash tender in major units, not inflated minor units', function () {
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'status' => 'checkout',
        'total_amount' => 10,
        'paid_amount' => 0,
    ]);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 10,
        'tendered_amount' => 10,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
        'idempotency_key' => 'usd-cash-major-1',
    ]);

    // $10.00 must persist as 10.00 major units — NOT 1000 (the minor value).
    expect($payment->status->value)->toBe('succeeded')
        ->and((float) $payment->amount)->toBe(10.0)
        ->and((float) $payment->tendered_amount)->toBe(10.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(10.0)
        ->and($order->fresh()->status->value)->toBe('closed');
});
