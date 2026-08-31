<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Device;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Models\ToppingGroupItem;
use Illuminate\Support\Str;

/**
 * Workstation LAN item sync — OrderLifecycleController::addItems is the Cloud
 * landing point for items added to an order in pos-web LAN mode. It must:
 *   - insert lines keyed by the workstation-supplied UUID,
 *   - be idempotent on replay (no duplicate rows),
 *   - converge a BR-OI06 merge quantity bump (same id, higher qty),
 *   - snapshot toppings + fold their surcharge into the line subtotal,
 *   - recompute the order's stored money fields so shop/HQ don't show ¥0.
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

    $this->taxType = TaxType::factory()->standard()->asDefault()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
});

function addSyncWsOrder(): CustomerOrder
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

function addSyncHeaders(): array
{
    return ['Authorization' => 'Bearer '.test()->wsToken];
}

function addSyncMenuSku(float $menuPrice): ProductSku
{
    $sku = ProductSku::factory()->create(['selling_price' => 999]);
    $sku->product->update([
        'tax_type_id' => test()->taxType->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
    $menu = Menu::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'status' => 'Active',
    ]);
    $menuProduct = MenuProduct::factory()->create(['menu_id' => $menu->id, 'is_active' => true]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => $menuPrice,
    ]);

    return $sku;
}

it('applies the active MenuPromotion so a synced item keeps its Happy Hour discount', function () {
    // Regression: the server re-priced synced items to the FULL menu price and
    // stripped the promo the Cloud cashier path had applied — a 1 997.50đ line
    // re-inflated to 2 350đ seconds after the customer ordered.
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'service_charge_rate' => 5,
        'currency_code' => 'JPY',
        'prices_include_tax' => false,
    ]);
    MenuPromotion::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'name' => 'HH',
        'discount_percent' => 15,
        'applies_to' => 'all_items',
        'stacking_mode' => 'stackable_with_coupons',
        'is_active' => true,
        // ISO 8601 weekdays, 1=Mon .. 7=Sun (see MenuPromotion.yaml). The old
        // [0..6] carried an invalid 0 and omitted 7, so this fixture silently
        // stopped matching every Sunday.
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);
    $order = addSyncWsOrder();
    $sku = addSyncMenuSku(1000);
    $itemId = (string) Str::uuid();

    $this->withHeaders(addSyncHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [[
                'id' => $itemId,
                'product_sku_id' => $sku->id,
                'quantity' => 1,
                // Workstation forwards the FULL price — server must discount it.
                'unit_price' => 1000,
            ]],
        ])
        ->assertOk();

    // 1000 − 15% = 850, NOT the full menu price of 1000.
    $item = CustomerOrderItem::findOrFail($itemId);
    expect((float) $item->unit_price)->toBe(850.0)
        ->and((float) $item->subtotal)->toBe(850.0);
});

it('inserts a workstation-keyed line and recomputes the order total', function () {
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'service_charge_rate' => 0,
        'currency_code' => 'JPY',
        'prices_include_tax' => false,
    ]);
    $order = addSyncWsOrder();
    $sku = addSyncMenuSku(800);
    $itemId = (string) Str::uuid();

    $this->withHeaders(addSyncHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [[
                'id' => $itemId,
                'product_sku_id' => $sku->id,
                'quantity' => 2,
            ]],
        ])
        ->assertOk();

    $item = CustomerOrderItem::findOrFail($itemId);
    expect((float) $item->subtotal)->toBe(1600.0);

    $order->refresh();
    // subtotal 1600, tax 10% = 160, total 1760.
    expect((float) $order->subtotal)->toBe(1600.0)
        ->and((float) $order->tax_amount)->toBe(160.0)
        ->and((float) $order->total_amount)->toBe(1760.0);
});

it('is idempotent — replaying the same line id does not duplicate it', function () {
    $order = addSyncWsOrder();
    $sku = addSyncMenuSku(500);
    $itemId = (string) Str::uuid();
    $payload = ['items' => [['id' => $itemId, 'product_sku_id' => $sku->id, 'quantity' => 1]]];

    $this->withHeaders(addSyncHeaders())->postJson("/api/v1/workstation/orders/{$order->id}/items", $payload)->assertOk();
    $this->withHeaders(addSyncHeaders())->postJson("/api/v1/workstation/orders/{$order->id}/items", $payload)->assertOk();

    expect(CustomerOrderItem::where('customer_order_id', $order->id)->count())->toBe(1);
    expect((float) CustomerOrderItem::findOrFail($itemId)->quantity)->toBe(1.0);
});

it('converges a BR-OI06 merge quantity bump on the same line id', function () {
    $order = addSyncWsOrder();
    $sku = addSyncMenuSku(500);
    $itemId = (string) Str::uuid();

    // First sync: qty 1. Second sync (a merge bumped the local line): qty 3.
    $this->withHeaders(addSyncHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [['id' => $itemId, 'product_sku_id' => $sku->id, 'quantity' => 1]],
        ])->assertOk();
    $this->withHeaders(addSyncHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [['id' => $itemId, 'product_sku_id' => $sku->id, 'quantity' => 3]],
        ])->assertOk();

    expect(CustomerOrderItem::where('customer_order_id', $order->id)->count())->toBe(1);
    $item = CustomerOrderItem::findOrFail($itemId);
    expect((float) $item->quantity)->toBe(3.0)
        ->and((float) $item->subtotal)->toBe(1500.0);
    expect((float) $order->fresh()->subtotal)->toBe(1500.0);
});

it('does not reset an item status that the KDS already advanced', function () {
    $order = addSyncWsOrder();
    $sku = addSyncMenuSku(500);
    $itemId = (string) Str::uuid();

    $this->withHeaders(addSyncHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [['id' => $itemId, 'product_sku_id' => $sku->id, 'quantity' => 1]],
        ])->assertOk();

    CustomerOrderItem::findOrFail($itemId)->update(['status' => 'preparing']);

    // A replay/merge bump must not knock the line back to pending.
    $this->withHeaders(addSyncHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [['id' => $itemId, 'product_sku_id' => $sku->id, 'quantity' => 2]],
        ])->assertOk();

    expect(CustomerOrderItem::findOrFail($itemId)->status->value)->toBe('preparing');
});

it('snapshots toppings and folds their surcharge into the line + order subtotal', function () {
    $order = addSyncWsOrder();
    $sku = addSyncMenuSku(800);
    $toppingSku = ProductSku::factory()->create(['selling_price' => 0]);
    $toppingItem = ToppingGroupItem::factory()->create();
    $itemId = (string) Str::uuid();

    $this->withHeaders(addSyncHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [[
                'id' => $itemId,
                'product_sku_id' => $sku->id,
                'quantity' => 2,
                'toppings' => [[
                    'topping_group_item_id' => $toppingItem->id,
                    'product_sku_id' => $toppingSku->id,
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
            ]],
        ])->assertOk();

    $item = CustomerOrderItem::with('orderItemToppings')->findOrFail($itemId);
    // unit_price 800 (server-resolved) + topping 100 = 900 per unit × 2 = 1800.
    expect((float) $item->topping_subtotal)->toBe(100.0)
        ->and((float) $item->subtotal)->toBe(1800.0)
        ->and($item->orderItemToppings)->toHaveCount(1);

    expect((float) $order->fresh()->subtotal)->toBe(1800.0);

    // Replay must not duplicate the topping row.
    $this->withHeaders(addSyncHeaders())
        ->postJson("/api/v1/workstation/orders/{$order->id}/items", [
            'items' => [[
                'id' => $itemId,
                'product_sku_id' => $sku->id,
                'quantity' => 2,
                'toppings' => [[
                    'topping_group_item_id' => $toppingItem->id,
                    'product_sku_id' => $toppingSku->id,
                    'quantity' => 1,
                    'unit_price' => 100,
                ]],
            ]],
        ])->assertOk();

    expect(CustomerOrderItem::with('orderItemToppings')->findOrFail($itemId)->orderItemToppings)->toHaveCount(1);
});
