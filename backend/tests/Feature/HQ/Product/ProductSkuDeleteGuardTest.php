<?php

// plan-042 GAP-4 — a Product/ProductSku referenced by an OPEN order (as a line
// item or a topping) cannot be deleted (409 + code). Existing menu / last-SKU
// guards must still fire.

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'slug' => 'acme-guard',
        'is_active' => true,
    ]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);
    $this->user = User::factory()->create(['console_organization_id' => $this->orgId]);
    grantOrgAccess($this->user, $this->orgId);

    // A product with TWO SKUs so deleting one doesn't trip the last-SKU guard.
    $this->product = Product::factory()->forBrand($this->brand)->create();
    $this->skuA = ProductSku::factory()->withSequencedOption()->create(['product_id' => $this->product->id]);
    $this->skuB = ProductSku::factory()->withSequencedOption()->create(['product_id' => $this->product->id]);
});

function openOrderWithSku(string $branchId, string $skuId): CustomerOrderItem
{
    $order = CustomerOrder::factory()->open()->create(['branch_id' => $branchId]);

    return CustomerOrderItem::factory()->create([
        'customer_order_id' => $order->id,
        'product_sku_id' => $skuId,
    ]);
}

it('blocks deleting a SKU used by an open order line item (409)', function () {
    openOrderWithSku($this->branch->id, $this->skuA->id);

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$this->skuA->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'PRODUCT_SKU_DELETE_BLOCKED_OPEN_ORDER');

    expect(ProductSku::withTrashed()->find($this->skuA->id)->deleted_at)->toBeNull();
});

it('blocks deleting a SKU used only as a topping on an open order (409)', function () {
    $item = openOrderWithSku($this->branch->id, $this->skuB->id);
    OrderItemTopping::factory()->create([
        'customer_order_item_id' => $item->id,
        'product_sku_id' => $this->skuA->id,
    ]);

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$this->skuA->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'PRODUCT_SKU_DELETE_BLOCKED_OPEN_ORDER');
});

it('blocks deleting a product whose SKU is on an open order (409)', function () {
    openOrderWithSku($this->branch->id, $this->skuA->id);

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/hq/{$this->brand->slug}/products/{$this->product->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'PRODUCT_DELETE_BLOCKED_OPEN_ORDER');

    expect(Product::withTrashed()->find($this->product->id)->deleted_at)->toBeNull();
    expect(ProductSku::withTrashed()->find($this->skuA->id)->deleted_at)->toBeNull();
});

it('allows deleting a SKU referenced only by a closed order (204)', function () {
    $order = CustomerOrder::factory()->closed()->create(['branch_id' => $this->branch->id]);
    CustomerOrderItem::factory()->create(['customer_order_id' => $order->id, 'product_sku_id' => $this->skuA->id]);

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$this->skuA->id}")
        ->assertNoContent();
});

it('still fires the last-SKU guard (422) when deleting the only SKU', function () {
    $solo = Product::factory()->forBrand($this->brand)->create();
    $onlySku = ProductSku::factory()->withSequencedOption()->create(['product_id' => $solo->id]);

    $this->actingAs($this->user)
        ->deleteJson("/api/v1/hq/{$this->brand->slug}/skus/{$onlySku->id}")
        ->assertStatus(422);
});
