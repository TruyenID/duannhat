<?php

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\FloatingSection;
use App\Models\FloatingSectionProductToppingItemOverride;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductToppingGroup;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Services\Customer\CustomerOrderService;
use App\Services\Promotion\FloatingSectionPriceResolver;
use Illuminate\Support\Str;

/**
 * #1180 — a floating section ("khung giờ ưu đãi") carries its own tier-1
 * topping price override, keyed by floating_section_product.
 *
 * The customer menu already PRICES the spotlight's toppings from that owner
 * (CustomerMenuService::transformToppingGroups passes it as resolver param 6),
 * but the order engine did not: ToppingSelectionPricer only ever passed the
 * MENU owner. A guest tapping a spotlight item was therefore shown the shop's
 * promo topping price and charged the HQ one — displayed money ≠ charged money,
 * the same defect class as #1192.
 *
 * Spotlight items ship `menu_product_sku_id: null` ("off-menu: the client
 * orders by product_sku_id only"), so an ABSENT explicit menu anchor is the
 * signal that the tap came from the spotlight.
 */
beforeEach(function () {
    $this->orgId = (string) Str::uuid();
    Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
    $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
    $this->branch = Branch::factory()->create([
        'console_organization_id' => $this->orgId,
        'console_brand_id' => $this->brand->console_brand_id,
        'is_active' => true,
    ]);

    // Parent product sold at 1000, with one topping group carrying one item
    // whose HQ base extra_price is 150.
    $this->product = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    $this->sku = ProductSku::factory()->create([
        'product_id' => $this->product->id,
        'selling_price' => 1000,
        'is_active' => true,
    ]);

    $this->group = ToppingGroup::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'price_strategy' => 'flat',
        'free_quantity' => 0,
        'is_active' => true,
        'min_select' => 0,
        'max_select' => 5,
    ]);
    ProductToppingGroup::factory()->create([
        'product_id' => $this->product->id,
        'topping_group_id' => $this->group->id,
    ]);

    $toppingProduct = Product::factory()->active()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'product_type_id' => $this->product->product_type_id,
    ]);
    $this->toppingSku = ProductSku::factory()->create([
        'product_id' => $toppingProduct->id,
        'selling_price' => 0,
        'is_active' => true,
    ]);
    $this->item = ToppingGroupItem::factory()->create([
        'topping_group_id' => $this->group->id,
        'product_id' => $toppingProduct->id,
        'is_default' => false,
    ]);
    ToppingGroupItemSku::factory()->create([
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'extra_price' => 150,
    ]);

    // An always-on floating section discounting the SKU to 800, with its own
    // topping price of 400 for the same topping.
    $this->floating = FloatingSection::factory()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
        'branch_id' => $this->branch->id,
        'is_active' => true,
        'priority' => 0,
        'start_date' => null,
        'end_date' => null,
    ]);
    $this->floating->schedules()->create([
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 0,
    ]);
    $this->floatingProduct = $this->floating->products()->create([
        'product_id' => $this->product->id,
        'is_active' => true,
        'display_order' => 0,
    ]);
    $this->floatingProduct->skus()->create([
        'product_sku_id' => $this->sku->id,
        'selling_price' => 800,
        'is_active' => true,
        'is_price_overridden' => true,
    ]);
});

function fsOrder(): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'ORD-'.Str::random(6),
        'order_type' => 'dine_in',
        'status' => 'open',
        'subtotal' => 0, 'discount_amount' => 0, 'service_charge' => 0,
        'tax_amount' => 0, 'total_amount' => 0, 'paid_amount' => 0, 'total_tip' => 0,
        'opened_at' => now(),
        'branch_id' => test()->branch->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);
}

function fsAddOneWithTopping(): CustomerOrderItem
{
    $items = app(CustomerOrderService::class)->addItems(fsOrder(), [
        'items' => [[
            'product_sku_id' => test()->sku->id,
            'quantity' => 1,
            'toppings' => [[
                'topping_group_item_id' => test()->item->id,
                'product_sku_id' => test()->toppingSku->id,
                'quantity' => 1,
            ]],
        ]],
    ]);

    return $items[0];
}

it('charges the floating section topping override, not the HQ price', function () {
    FloatingSectionProductToppingItemOverride::create([
        'floating_section_product_id' => $this->floatingProduct->id,
        'topping_group_id' => $this->group->id,
        'topping_group_item_id' => $this->item->id,
        'product_sku_id' => $this->toppingSku->id,
        'is_hidden' => false,
        'override_price' => 400,
    ]);

    $item = fsAddOneWithTopping();

    // Unit price is the promo 800; the topping must be the promo 400, not 150.
    expect((float) $item->unit_price)->toBe(800.0)
        ->and((float) $item->topping_subtotal)->toBe(400.0)
        ->and((float) $item->subtotal)->toBe(1200.0);
});

it('still uses the HQ base price when the floating section sets no override', function () {
    $item = fsAddOneWithTopping();

    expect((float) $item->unit_price)->toBe(800.0)
        ->and((float) $item->topping_subtotal)->toBe(150.0);
});

it('exposes the winning floating_section_product on the resolved price row', function () {
    $resolved = app(FloatingSectionPriceResolver::class)
        ->resolveForSkus((string) $this->branch->id, [(string) $this->sku->id]);

    expect($resolved[(string) $this->sku->id]['floating_section_product_id'])
        ->toBe((string) $this->floatingProduct->id)
        ->and($resolved[(string) $this->sku->id]['price'])->toBe(800.0);
});
