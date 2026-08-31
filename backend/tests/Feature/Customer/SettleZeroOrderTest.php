<?php

/**
 * Zero-due dine-in settlement — a ¥0 bill (fully-comped item, 100%-off coupon)
 * cannot go through Stripe (it rejects a 0-amount PaymentIntent), so
 * customer-web closes it via POST /orders/{id}/settle-zero. This endpoint must:
 *   - close a genuinely zero-due order (status → closed) without any payment,
 *   - be idempotent (a retried request on an already-closed order returns 200),
 *   - REFUSE to free-close an order that still owes a balance (money-safety),
 *   - refuse a voided order.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Omnify\Enums\CustomerOrderStatusEnum;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->id,
    ]);
});

/**
 * A dine-in order with a single made_to_order / no-recipe SKU so close() skips
 * all stock-out setup. Amounts are caller-supplied to cover the zero-due and
 * still-owing cases.
 */
function settleZeroOrder(float $total, float $paid, string $status = 'open'): CustomerOrder
{
    $sku = ProductSku::factory()->create(['inventory_mode' => 'made_to_order', 'recipe_id' => null]);

    $order = CustomerOrder::factory()->create([
        'organization_id' => test()->orgId,
        'branch_id' => test()->branch->id,
        'status' => $status,
        'total_amount' => $total,
        'paid_amount' => $paid,
    ]);
    CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'status' => 'served',
    ]);

    return $order;
}

it('closes a zero-due order without any payment', function () {
    $order = settleZeroOrder(total: 0, paid: 0);

    $this->postJson("/api/v1/customer/orders/{$order->id}/settle-zero")
        ->assertOk()
        ->assertJsonPath('data.status', CustomerOrderStatusEnum::Closed->value);

    $fresh = CustomerOrder::find($order->id);
    expect($fresh->status)->toBeIn([CustomerOrderStatusEnum::Closed, CustomerOrderStatusEnum::Closed->value]);
    expect((float) $fresh->paid_amount)->toBe(0.0);
    expect($fresh->closed_at)->not->toBeNull();
});

it('is idempotent for an already-closed order', function () {
    $order = settleZeroOrder(total: 0, paid: 0);

    $this->postJson("/api/v1/customer/orders/{$order->id}/settle-zero")->assertOk();
    // Retry (double-tap / network retry) must be a 200 no-op, never a 4xx.
    $this->postJson("/api/v1/customer/orders/{$order->id}/settle-zero")
        ->assertOk()
        ->assertJsonPath('data.status', CustomerOrderStatusEnum::Closed->value);
});

it('refuses to free-close an order that still owes a balance', function () {
    $order = settleZeroOrder(total: 1500, paid: 0);

    $this->postJson("/api/v1/customer/orders/{$order->id}/settle-zero")
        ->assertStatus(422);

    expect(CustomerOrder::find($order->id)->status)
        ->toBeIn([CustomerOrderStatusEnum::Open, CustomerOrderStatusEnum::Open->value]);
});

it('refuses a voided order', function () {
    $order = settleZeroOrder(total: 0, paid: 0, status: CustomerOrderStatusEnum::Voided->value);

    $this->postJson("/api/v1/customer/orders/{$order->id}/settle-zero")
        ->assertStatus(422);
});

it('404s an unknown order', function () {
    $this->postJson('/api/v1/customer/orders/'.Str::uuid().'/settle-zero')
        ->assertStatus(404);
});
