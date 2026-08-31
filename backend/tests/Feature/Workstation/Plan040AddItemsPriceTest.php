<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\ProductSku;
use Illuminate\Support\Str;

/**
 * plan-040 H17 — the LAN client is untrusted. OrderLifecycleController::addItems
 * must resolve each item's unit_price from the authoritative active menu
 * server-side, reject off-menu SKUs, and never persist a client-supplied price.
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

function pj40WsOrder(): CustomerOrder
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

function pj40WsHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->wsToken];
}

/**
 * A SKU that is on an active menu belonging to the ORDER'S branch at the given
 * selling price. plan-040 H17 refinement: the controller scopes the menu price
 * lookup to the order's branch menu, so the MenuProductSku must hang off a Menu
 * whose branch_id == the workstation branch.
 */
function pj40MenuSku(float $menuPrice): ProductSku
{
    $sku = ProductSku::factory()->create(['selling_price' => 999]);

    $menu = Menu::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'status' => 'Active',
    ]);
    $menuProduct = MenuProduct::factory()->create([
        'menu_id' => $menu->id,
        'is_active' => true,
    ]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => $menuPrice,
    ]);

    return $sku;
}

it('overrides a tampered (below-menu) client unit_price with the authoritative price', function () {
    $order = pj40WsOrder();
    $sku = pj40MenuSku(800);

    $this->withHeaders(pj40WsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [[
                'product_sku_id' => $sku->id,
                'quantity' => 2,
                'unit_price' => 1, // tampered far below the menu price
            ]],
        ])
        ->assertOk();

    $item = CustomerOrderItem::where('customer_order_id', $order->id)->firstOrFail();
    expect((float) $item->unit_price)->toBe(800.0)
        ->and((float) $item->subtotal)->toBe(1600.0);
});

it('accepts an off-menu SKU using the sku selling_price and still overrides the client price', function () {
    // plan-040 H17 refinement: a legitimately off-menu workstation sale must NOT
    // be 422'd — it falls back to ProductSku::selling_price (mirroring the Cloud
    // CustomerOrderService::addItems path) while still ignoring the client price.
    $order = pj40WsOrder();
    $offMenuSku = ProductSku::factory()->create(['selling_price' => 500]); // no branch-menu line

    $this->withHeaders(pj40WsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [[
                'product_sku_id' => $offMenuSku->id,
                'quantity' => 2,
                'unit_price' => 1, // tampered — must be overridden by the fallback
            ]],
        ])
        ->assertOk();

    $item = CustomerOrderItem::where('customer_order_id', $order->id)->firstOrFail();
    expect((float) $item->unit_price)->toBe(500.0)
        ->and((float) $item->subtotal)->toBe(1000.0);
});

it('still rejects an unknown SKU with 422', function () {
    $order = pj40WsOrder();

    $this->withHeaders(pj40WsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [[
                'product_sku_id' => (string) Str::uuid(), // no such ProductSku
                'quantity' => 1,
                'unit_price' => 500,
            ]],
        ])
        ->assertStatus(422);

    expect(CustomerOrderItem::where('customer_order_id', $order->id)->count())->toBe(0);
});

it('rejects a non-positive quantity with 422', function () {
    $order = pj40WsOrder();
    $sku = pj40MenuSku(800);

    $this->withHeaders(pj40WsHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [[
                'product_sku_id' => $sku->id,
                'quantity' => 0,
                'unit_price' => 800,
            ]],
        ])
        ->assertStatus(422);
});
