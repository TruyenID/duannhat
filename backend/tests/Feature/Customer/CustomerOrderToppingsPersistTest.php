<?php

/**
 * Regression: customer-web POST /customer/tables/{qr}/orders dropped the
 * `toppings` payload before it reached CustomerOrderService because
 * CustomerOrderStoreRequest had no rule for `items.*.toppings.*`, so
 * $request->validated() stripped the field. Bill in kiosk / workstation
 * printed without toppings even though the order itself was created.
 *
 * Asserts the FormRequest now threads toppings through to
 * order_item_toppings.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\OrderItemTopping;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\Table;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Models\Zone;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create([
        'id' => $this->orgId,
        'console_organization_id' => $this->orgId,
    ]);

    $this->brand = Brand::factory()->create([
        'console_organization_id' => $this->orgId,
        'is_active' => true,
    ]);

    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
    ]);

    $this->zone = Zone::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
    ]);

    $this->table = Table::factory()->create([
        'organization_id' => $this->orgId,
        'branch_id' => $this->branch->id,
        'zone_id' => $this->zone->id,
        'qr_token' => 'topping-persist-token',
        'is_active' => true,
        'status' => 'free',
    ]);

    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'selling_price' => 1000,
    ]);

    $this->toppingGroup = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => null,
        'modifier_type' => 'add',
        'selection_type' => 'multiple',
        'price_strategy' => 'flat',
    ]);

    $toppingProduct = Product::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'is_active' => true,
        'selling_price' => 50,
    ]);
    $this->toppingItem = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->toppingGroup->id,
        'product_id' => $toppingProduct->id,
        'is_default' => false,
        'sort_order' => 0,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->toppingItem->id,
        'product_sku_id' => $this->toppingSku->id,
        'extra_price' => 50,
    ]);

    $this->product->toppingGroups()->attach($this->toppingGroup->id, ['sort_order' => 0]);
});

it('persists order_item_toppings rows when customer-web posts toppings', function () {
    $response = $this->postJson('/api/v1/customer/tables/topping-persist-token/orders', [
        'items' => [
            [
                'product_sku_id' => $this->sku->id,
                'quantity' => 1,
                'toppings' => [
                    [
                        'topping_group_item_id' => $this->toppingItem->id,
                        'product_sku_id' => $this->toppingSku->id,
                        'quantity' => 1,
                    ],
                ],
            ],
        ],
    ]);

    $response->assertStatus(201);

    $order = CustomerOrder::where('branch_id', $this->branch->id)->latest()->first();
    expect($order)->not->toBeNull();

    $orderItem = $order->items()->first();
    expect($orderItem)->not->toBeNull();

    $toppingRows = OrderItemTopping::where('customer_order_item_id', $orderItem->id)->get();
    expect($toppingRows)->toHaveCount(1);
    expect((string) $toppingRows->first()->topping_group_item_id)->toBe((string) $this->toppingItem->id);
    expect((float) $toppingRows->first()->unit_price)->toBe(50.0);
});
