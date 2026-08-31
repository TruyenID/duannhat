<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Omnify\Enums\ProductStatusEnum;
use Illuminate\Support\Str;

/**
 * #902 — the workstation LAN doors that persist a CustomerOrderItem from a
 * client-supplied product_sku_id must apply the SAME sellability gate as the
 * Cloud addItems path, so a draft / paused product cannot be back-doored onto
 * Cloud via sync-UP or a KDS bump.
 *
 *  - Gate #2: POST /workstation/orders/{order}/items  (resolveAuthoritativeItemPrices)
 *  - Gate #3: PATCH /workstation/orders/{order}/items/{item}/status ghost-create
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    $this->wsToken = Str::random(64);
    Device::factory()->create([
        'type' => 'workstation',
        'status' => 'active',
        'device_token' => $this->wsToken,
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);
});

function wsgOrder(): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'WS-'.Str::random(5),
        'order_type' => 'spot',
        'status' => 'open',
        'subtotal' => 0,
        'discount_amount' => 0,
        'service_charge' => 0,
        'tax_amount' => 0,
        'total_amount' => 0,
        'paid_amount' => 0,
        'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

function wsgHeaders(array $extra = []): array
{
    return array_merge(['Authorization' => 'Bearer '.test()->wsToken], $extra);
}

function wsgSku(string $status): ProductSku
{
    $product = Product::factory()->create(['status' => $status]);

    return ProductSku::factory()->create(['product_id' => $product->id, 'selling_price' => 500]);
}

// =========================================================================
//  Gate #2 — sync-UP items
// =========================================================================

it('accepts syncing a line for an active product', function () {
    $order = wsgOrder();
    $sku = wsgSku(ProductStatusEnum::Active->value);

    $this->withHeaders(wsgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [['product_sku_id' => $sku->id, 'quantity' => 1]],
        ])
        ->assertOk();

    expect(CustomerOrderItem::where('customer_order_id', $order->id)->count())->toBe(1);
});

it('rejects syncing a line for a non-sellable product with 422', function (string $status) {
    $order = wsgOrder();
    $sku = wsgSku($status);

    $this->withHeaders(wsgHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [['product_sku_id' => $sku->id, 'quantity' => 1]],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('items.0.product_sku_id');

    expect(CustomerOrderItem::where('customer_order_id', $order->id)->count())->toBe(0);
})->with([
    'draft' => [ProductStatusEnum::Draft->value],
    'inactive' => [ProductStatusEnum::Inactive->value],
]);

// =========================================================================
//  Gate #3 — KDS-bump ghost-create
// =========================================================================

it('rejects ghost-creating an order item for a non-sellable product', function () {
    $order = wsgOrder();
    $sku = wsgSku(ProductStatusEnum::Draft->value);
    $ghostId = (string) Str::uuid();

    $this->withHeaders(wsgHeaders(['Idempotency-Key' => (string) Str::uuid()]))
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$ghostId}/status", [
            'status' => 'preparing',
            'item_snapshot' => [
                'product_sku_id' => $sku->id,
                'quantity' => 1,
                'unit_price' => 500,
            ],
        ])
        ->assertStatus(422);

    expect(CustomerOrderItem::where('id', $ghostId)->exists())->toBeFalse();
});

it('ghost-creates an order item for a sellable product', function () {
    $order = wsgOrder();
    $sku = wsgSku(ProductStatusEnum::Active->value);
    $ghostId = (string) Str::uuid();

    $this->withHeaders(wsgHeaders(['Idempotency-Key' => (string) Str::uuid()]))
        ->patchJson("/api/v1/workstation/orders/{$order->id}/items/{$ghostId}/status", [
            'status' => 'preparing',
            'item_snapshot' => [
                'product_sku_id' => $sku->id,
                'quantity' => 1,
                'unit_price' => 500,
            ],
        ])
        ->assertOk();

    expect(CustomerOrderItem::where('id', $ghostId)->exists())->toBeTrue();
});
