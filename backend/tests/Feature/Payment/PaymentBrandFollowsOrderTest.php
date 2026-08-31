<?php

/**
 * #1800 — a payment's brand is the ORDER's brand, never the caller's context.
 *
 * Every transport derived `brand_id` from wherever it happened to stand — POS
 * from `$request->attributes`, kiosk/workstation from `$branch->brand->id` —
 * and nothing compared it to the order. A branch whose brand differs from the
 * order's therefore wrote the payment into the wrong brand. Money totals stay
 * correct (they key on the order), so nothing goes red; only brand-scoped
 * reporting quietly lies, and nothing downstream re-derives the brand — which
 * is why the write path corrects it and logs `payment_brand_corrected_from_order`.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\Organization;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Customer\OrderPaymentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->organizationId = (string) Str::uuid();

    Organization::factory()->create([
        'id' => $this->organizationId,
        'console_organization_id' => $this->organizationId,
    ]);

    $this->orderBrand = Brand::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    // A second brand in the same organization — the value a caller standing at
    // a re-branded branch would hand in.
    $this->otherBrand = Brand::factory()->create([
        'console_organization_id' => $this->organizationId,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->organizationId,
        'console_brand_id' => $this->orderBrand->console_brand_id,
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

function orderForBrandTest(string $organizationId, string $brandId, string $branchId): CustomerOrder
{
    return CustomerOrder::factory()->create([
        'organization_id' => $organizationId,
        'brand_id' => $brandId,
        'branch_id' => $branchId,
        'status' => 'checkout',
        'total_amount' => 800,
        'paid_amount' => 0,
    ]);
}

it('stamps the order brand even when the caller supplies a different one', function () {
    $order = orderForBrandTest($this->organizationId, $this->orderBrand->id, $this->branch->id);

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 800,
        'tendered_amount' => 800,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        // The defect: caller hands in the OTHER brand.
        'brand_id' => $this->otherBrand->id,
        'branch_id' => $this->branch->id,
        'idempotency_key' => 'brand-follows-order-1',
    ]);

    expect((string) $payment->brand_id)->toBe((string) $order->brand_id)
        ->and((string) $payment->brand_id)->not->toBe((string) $this->otherBrand->id);
});

it('logs which transport supplied the wrong brand instead of silently patching it', function () {
    $order = orderForBrandTest($this->organizationId, $this->orderBrand->id, $this->branch->id);

    $logged = [];
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$logged) {
        $logged[] = [$message, $context];
    });
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();

    app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 800,
        'tendered_amount' => 800,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->otherBrand->id,
        'branch_id' => $this->branch->id,
        'orchestrator_transport' => 'pos',
        'idempotency_key' => 'brand-follows-order-2',
    ]);

    $corrections = array_values(array_filter(
        $logged,
        static fn (array $entry): bool => $entry[0] === 'payment_brand_corrected_from_order'
    ));

    expect($corrections)->toHaveCount(1)
        ->and($corrections[0][1]['order_brand_id'])->toBe((string) $this->orderBrand->id)
        ->and($corrections[0][1]['caller_brand_id'])->toBe((string) $this->otherBrand->id);
});

it('stays quiet when the caller already agrees with the order', function () {
    $order = orderForBrandTest($this->organizationId, $this->orderBrand->id, $this->branch->id);

    $logged = [];
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context = []) use (&$logged) {
        $logged[] = $message;
    });
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();

    $payment = app(OrderPaymentService::class)->create([
        'customer_order_id' => $order->id,
        'payment_method_id' => $this->cash->id,
        'amount' => 800,
        'tendered_amount' => 800,
        'received_by_id' => $this->operator->id,
        'organization_id' => $this->organizationId,
        'brand_id' => $this->orderBrand->id,
        'branch_id' => $this->branch->id,
        'idempotency_key' => 'brand-follows-order-3',
    ]);

    expect((string) $payment->brand_id)->toBe((string) $this->orderBrand->id)
        ->and($logged)->not->toContain('payment_brand_corrected_from_order');
});
