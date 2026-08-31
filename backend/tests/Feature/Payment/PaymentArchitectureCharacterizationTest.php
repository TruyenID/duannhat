<?php

use App\Events\OrderPaid;
use App\Events\OrderPaymentRecorded;
use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderPayment;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

it('keeps the proposed ownership fixture complete and fail-closed', function () {
    $fixture = json_decode(
        file_get_contents(base_path('tests/Fixtures/Payment/branch-management-contract.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $cases = collect($fixture['cases'])->keyBy('name');

    expect($fixture['contract_version'])->toBe('proposal-v1')
        ->and($fixture['source'])->toBe('platform')
        ->and($cases->keys()->all())->toContain(
            'hq_managed',
            'active_franchise',
            'suspended_franchise',
            'expired_franchise',
            'ambiguous_active_franchise',
            'missing_franchise_grant',
            'revoked_franchise',
            'cross_tenant_franchise',
        );

    foreach ([
        'suspended_franchise',
        'expired_franchise',
        'ambiguous_active_franchise',
        'missing_franchise_grant',
        'revoked_franchise',
        'cross_tenant_franchise',
    ] as $name) {
        expect($cases[$name]['expected'])->toBe('unresolved');
    }
});

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
        'is_active' => true,
    ]);

    $this->operator = User::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    $this->cash = PaymentMethod::factory()->cash()->create([
        'organization_id' => $this->organizationId,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'is_auto_confirm' => true,
        'requires_tendered' => true,
    ]);

    $this->payments = app(OrderPaymentService::class);
});

it('characterizes partial payment as ledger success plus payment-recorded without order-paid', function () {
    // Given an unpaid takeaway order and an auto-confirm cash method.
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);
    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    // When the canonical service records only 400 of the 1000 balance.
    $payment = $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 400,
        'tendered_amount' => 400,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    // Then the payment is immutable succeeded money, the order remains paying,
    // and clients receive only the partial-payment signal.
    expect($payment->status->value)->toBe('succeeded')
        ->and((float) $payment->amount)->toBe(400.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(400.0)
        ->and($order->fresh()->status->value)->toBe('paying');
    Event::assertDispatchedTimes(OrderPaymentRecorded::class, 1);
    Event::assertNotDispatched(OrderPaid::class);
});

it('characterizes a settling payment as one close and one order-paid signal', function () {
    // Given an order whose remaining balance is fully covered by this command.
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'order_type' => 'takeaway',
        'status' => 'checkout',
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);
    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);

    // When the canonical service records the final cash payment.
    $payment = $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 1000,
        'tendered_amount' => 1000,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    // Then ledger success and order settlement happen in the same application
    // path, and the partial-payment event is not emitted as a duplicate signal.
    expect($payment->status->value)->toBe('succeeded')
        ->and($order->fresh()->status->value)->toBe('closed')
        ->and((float) $order->fresh()->paid_amount)->toBe(1000.0)
        ->and($order->fresh()->closed_at)->not->toBeNull();
    Event::assertDispatchedTimes(OrderPaid::class, 1);
    Event::assertNotDispatched(OrderPaymentRecorded::class);
});

it('characterizes collected balance across partial refund and unrelated debt settlement rows', function () {
    // Given 1000 collected as two sale rows, with 200 refunded from the first
    // row, plus pending/failed noise and a 900 debt settlement riding this order.
    $order = CustomerOrder::factory()->create([
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'order_type' => 'takeaway',
        'status' => 'paying',
        'total_amount' => 1000,
        'paid_amount' => 0,
    ]);

    $refundedOriginal = OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 600,
        'status' => 'refunded',
    ]);
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => -200,
        'status' => 'succeeded',
        'refund_of_id' => $refundedOriginal->id,
    ]);
    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 400,
        'status' => 'succeeded',
    ]);

    foreach (['pending', 'failed'] as $status) {
        OrderPayment::factory()->create([
            'customer_order_id' => $order->id,
            'payment_method_id' => $this->cash->id,
            'organization_id' => $this->organizationId,
            'brand_id' => $this->brand->id,
            'branch_id' => $this->branch->id,
            'amount' => 500,
            'status' => $status,
            'expires_at' => now()->subMinute(),
        ]);
    }

    OrderPayment::factory()->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'amount' => 900,
        'status' => 'succeeded',
        'metadata' => ['settles_payment_id' => (string) Str::uuid()],
    ]);

    // Then only 600 - 200 + 400 = 800 belongs to this order.
    expect(OrderPayment::netCollectedForOrder($order->id))->toBe(800.0);

    // When another 200 is collected, the service uses the same projection,
    // accepts exactly the outstanding balance, and closes at 1000 rather than
    // treating pending/failed/debt rows as order revenue.
    Event::fake([OrderPaid::class, OrderPaymentRecorded::class]);
    $this->payments->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 200,
        'tendered_amount' => 200,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
    ]);

    expect(OrderPayment::netCollectedForOrder($order->id))->toBe(1000.0)
        ->and((float) $order->fresh()->paid_amount)->toBe(1000.0)
        ->and($order->fresh()->status->value)->toBe('closed');
    Event::assertDispatchedTimes(OrderPaid::class, 1);
});
