<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\FloatingSection;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuPromotion;
use App\Models\OrderCondition;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Omnify\Enums\OrderItemPriceSourceEnum;
use App\Services\Customer\CustomerOrderService;
use Illuminate\Support\Str;

/**
 * #2618 (ruling #2132 §B) — `price_source` snapshot: nguồn đã QUYẾT giá cuối
 * của dòng, đóng dấu tại chính chỗ precedence chạy trong addItems:
 *
 *   menu | sku_base  →  floating CHỈ KHI THẤP HƠN  →  menu_promotion.
 *
 * Ca quan trọng nhất: floating CAO HƠN giá menu ⇒ min() giữ giá menu ⇒ nguồn
 * là `menu`, KHÔNG phải `floating` — floating "tồn tại" không phải là floating
 * "thắng", và một snapshot sai còn tệ hơn không có snapshot vì nó được tin.
 *
 * NULL có nghĩa riêng: engine định giá của Cloud không quyết giá dòng đó
 * (workstation sync-UP transport, ghost line KDS — unit_price do thiết bị
 * khai). Dòng hoàn COPY nguyên price_source của dòng gốc.
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
        'timezone' => 'Asia/Tokyo',
        'is_active' => true,
    ]);
    $this->taxType = TaxType::factory()->standard()->asDefault()->create([
        'organization_id' => $this->orgId,
        'brand_id' => $this->brand->id,
    ]);
    ShopOrderSetting::factory()->create([
        'branch_id' => $this->branch->id,
        'organization_id' => $this->orgId,
        'service_charge_rate' => 0,
        'currency_code' => 'JPY',
        'prices_include_tax' => false,
    ]);

    $this->service = app(CustomerOrderService::class);
});

function pssOrder(): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'PSS-'.Str::random(6),
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

/** SKU có dòng menu active trên branch với giá $menuPrice. */
function pssMenuSku(float $menuPrice, float $skuPrice = 999999): ProductSku
{
    $sku = ProductSku::factory()->create(['selling_price' => $skuPrice]);
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
    $menuProduct = MenuProduct::factory()->create(['menu_id' => $menu->id, 'product_id' => $sku->product_id, 'is_active' => true]);
    MenuProductSku::factory()->create([
        'menu_product_id' => $menuProduct->id,
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => $menuPrice,
    ]);

    return $sku;
}

/** SKU KHÔNG nằm trên menu nào — off-menu, giá là selling_price của chính SKU. */
function pssBareSku(float $skuPrice): ProductSku
{
    $sku = ProductSku::factory()->create(['selling_price' => $skuPrice]);
    $sku->product->update([
        'tax_type_id' => test()->taxType->id,
        'brand_id' => test()->brand->id,
        'organization_id' => test()->orgId,
    ]);

    return $sku;
}

/** Floating section active MỌI ngày, MỌI giờ, chào $price cho $sku trên branch. */
function pssFloating(ProductSku $sku, float $price): FloatingSection
{
    $section = FloatingSection::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'is_active' => true,
        'start_date' => null,
        'end_date' => null,
    ]);
    $section->schedules()->create([
        'start_time' => '00:00:00',
        'end_time' => '23:59:59',
        // Carbon dayOfWeek 0..6 → bit 1 << dayOfWeek; 127 phủ cả tuần.
        'days_of_week' => 127,
        'is_active' => true,
        'priority' => 0,
    ]);
    $floatingProduct = $section->products()->create([
        'product_id' => $sku->product_id,
        'is_active' => true,
        'display_order' => 1,
    ]);
    $floatingProduct->skus()->create([
        'product_sku_id' => $sku->id,
        'is_active' => true,
        'selling_price' => $price,
        'is_price_overridden' => true,
    ]);

    return $section;
}

function pssPromotion(float $discountPercent): MenuPromotion
{
    return MenuPromotion::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'name' => 'PSS HH',
        'discount_percent' => $discountPercent,
        'applies_to' => 'all_items',
        'stacking_mode' => 'stackable_with_coupons',
        'is_active' => true,
        'weekdays' => [1, 2, 3, 4, 5, 6, 7],
        'valid_from' => now()->subDay(),
        'valid_until' => now()->addMonth(),
    ]);
}

// ---------------------------------------------------------------------------
// Bốn nhánh precedence — mỗi nhánh đúng MỘT nguồn
// ---------------------------------------------------------------------------

it('stamps price_source = menu when the menu line priced the line', function () {
    $order = pssOrder();
    $sku = pssMenuSku(800);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 1,
    ]]]);

    $item = $order->items()->firstOrFail();
    expect((float) $item->unit_price)->toBe(800.0)
        ->and($item->price_source)->toBe(OrderItemPriceSourceEnum::Menu);
});

it('stamps price_source = sku_base on an off-menu line priced by the SKU itself', function () {
    $order = pssOrder();
    $sku = pssBareSku(650);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 1,
    ]]]);

    $item = $order->items()->firstOrFail();
    expect((float) $item->unit_price)->toBe(650.0)
        ->and($item->price_source)->toBe(OrderItemPriceSourceEnum::SkuBase);
});

it('stamps price_source = floating ONLY when the floating price actually undercuts the menu', function () {
    $order = pssOrder();
    $sku = pssMenuSku(1000);
    pssFloating($sku, 600);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 1,
    ]]]);

    $item = $order->items()->firstOrFail();
    expect((float) $item->unit_price)->toBe(600.0)
        ->and($item->price_source)->toBe(OrderItemPriceSourceEnum::Floating);
});

it('stamps price_source = menu_promotion when a promotion decided the final price', function () {
    pssPromotion(15);
    $order = pssOrder();
    $sku = pssMenuSku(1000);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 1,
    ]]]);

    $item = $order->items()->firstOrFail();
    expect((float) $item->unit_price)->toBe(850.0)
        ->and((float) $item->original_unit_price)->toBe(1000.0)
        ->and($item->price_source)->toBe(OrderItemPriceSourceEnum::MenuPromotion);
});

// ---------------------------------------------------------------------------
// Ca quan trọng nhất — floating TỒN TẠI nhưng KHÔNG thắng
// ---------------------------------------------------------------------------

it('keeps price_source = menu when the floating price is HIGHER than the menu price', function () {
    $order = pssOrder();
    $sku = pssMenuSku(1000);
    pssFloating($sku, 1200);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 1,
    ]]]);

    $item = $order->items()->firstOrFail();
    // min() giữ giá menu — floating tồn tại nhưng KHÔNG quyết giá này.
    expect((float) $item->unit_price)->toBe(1000.0)
        ->and($item->price_source)->toBe(OrderItemPriceSourceEnum::Menu);
});

it('keeps price_source = menu on a floating TIE — equal price is not a win', function () {
    $order = pssOrder();
    $sku = pssMenuSku(1000);
    pssFloating($sku, 1000);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 1,
    ]]]);

    $item = $order->items()->firstOrFail();
    expect((float) $item->unit_price)->toBe(1000.0)
        ->and($item->price_source)->toBe(OrderItemPriceSourceEnum::Menu);
});

// ---------------------------------------------------------------------------
// Dòng hoàn — copy nguyên dấu vết, không tính lại
// ---------------------------------------------------------------------------

it('copies price_source verbatim onto the refund line', function () {
    pssPromotion(15);
    $order = pssOrder();
    $sku = pssMenuSku(1000);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 2,
    ]]]);
    $source = $order->items()->firstOrFail();

    test()->service->refundItem($order->fresh(), (string) $source->id, 1.0, 'test-refund');

    $refund = $order->items()->where('refund_of_item_id', $source->id)->firstOrFail();
    expect($refund->price_source)->toBe(OrderItemPriceSourceEnum::MenuPromotion);
});

// ---------------------------------------------------------------------------
// Đường KHÔNG chạy engine định giá của Cloud — NULL có chủ đích
// ---------------------------------------------------------------------------

it('leaves price_source NULL on a workstation ghost line (device-claimed price)', function () {
    $order = pssOrder();
    $sku = pssMenuSku(700);
    $ghostId = (string) Str::uuid();

    test()->service->ghostCreateWorkstationItem($order, $ghostId, [
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'unit_price' => 700,
    ]);

    expect(CustomerOrderItem::findOrFail($ghostId)->price_source)->toBeNull();
});

it('leaves price_source NULL on a workstation sync-UP line (device-claimed price)', function () {
    $order = pssOrder();
    $sku = pssMenuSku(800);
    $itemId = (string) Str::uuid();

    test()->service->syncWorkstationItems($order, [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'unit_price' => 800,
    ]], [0 => 800.0]);

    expect(CustomerOrderItem::findOrFail($itemId)->price_source)->toBeNull();
});

// ---------------------------------------------------------------------------
// Rào ruling #2132 §B — snapshot KHÔNG kéo theo dòng sổ nào
// ---------------------------------------------------------------------------

it('grows no order_conditions row carrying a price-formation source', function () {
    pssPromotion(15);
    $order = pssOrder();
    $sku = pssMenuSku(1000);
    pssFloating($sku, 600);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 1,
    ]]]);

    $sources = OrderCondition::query()
        ->where('conditionable_type', $order->getMorphClass())
        ->where('conditionable_id', $order->id)
        ->pluck('source')
        ->all();

    foreach (['menu_promotion', 'price_override', 'free_topping', 'floating', 'sku_base', 'menu'] as $banned) {
        expect(in_array($banned, $sources, true))->toBeFalse(
            "sổ mọc dòng source = {$banned} — price_source là item-snapshot, không bao giờ vào order_conditions"
        );
    }
});
