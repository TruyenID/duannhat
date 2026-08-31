<?php

declare(strict_types=1);

use App\Models\Branch;
use App\Models\Brand;
use App\Models\CustomerOrder;
use App\Models\CustomerOrderItem;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\MenuPromotion;
use App\Models\Organization;
use App\Models\ProductSku;
use App\Models\ShopOrderSetting;
use App\Models\TaxType;
use App\Services\Customer\CustomerOrderService;
use App\Services\Promotion\MenuPromotionService;
use Illuminate\Support\Str;

/**
 * #2617 (ruling #2132 §B) — `original_unit_price` là dấu vết định hình giá
 * BẮT BUỘC trên MỌI dòng đơn, không chỉ dòng khuyến mãi:
 *
 *   - không cơ chế nào hạ giá  → original == unit (dấu vết "không giảm");
 *   - khuyến mãi thực đơn      → original = giá niêm yết, unit = giá đã hạ;
 *   - dòng hoàn                → COPY NGUYÊN original của dòng bị hoàn;
 *   - hai reader báo cáo khuyến mãi (withSum của MenuPromotionService và
 *     summaryForPromotion) phải ra CÙNG một số với phép cộng trực tiếp trên
 *     snapshot — họ lỗi hai-reader-lệch-nhau #2074.
 *
 * Bài học #2411/#2543: đo dữ liệu đang có KHÔNG phải đo mọi đường ghi — file
 * này ghim từng đường ghi một (addItems, refund, ghost, workstation sync;
 * đường typed/evidence ghim ở TypedOrderCreateEndToEndTest).
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

function oupOrder(): CustomerOrder
{
    return CustomerOrder::create([
        'order_code' => 'OUP-'.Str::random(6),
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

function oupMenuSku(float $menuPrice): ProductSku
{
    $sku = ProductSku::factory()->create(['selling_price' => 999999]);
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

function oupPromotion(float $discountPercent): MenuPromotion
{
    return MenuPromotion::factory()->create([
        'organization_id' => test()->orgId,
        'brand_id' => test()->brand->id,
        'branch_id' => test()->branch->id,
        'name' => 'OUP HH',
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
// addItems — đường chính (POS/customer/kiosk/continue-table đều đổ về đây)
// ---------------------------------------------------------------------------

it('stamps original_unit_price == unit_price on a line no mechanism discounted', function () {
    $order = oupOrder();
    $sku = oupMenuSku(800);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 2,
    ]]]);

    $item = $order->items()->firstOrFail();
    expect($item->original_unit_price)->not->toBeNull()
        ->and((float) $item->original_unit_price)->toBe(800.0)
        ->and((float) $item->unit_price)->toBe(800.0)
        ->and($item->applied_promotion_id)->toBeNull();
});

it('stamps the strikethrough (original > unit) on a promotion line', function () {
    $promotion = oupPromotion(15);
    $order = oupOrder();
    $sku = oupMenuSku(1000);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 1,
    ]]]);

    $item = $order->items()->firstOrFail();
    expect((float) $item->unit_price)->toBe(850.0)
        ->and((float) $item->original_unit_price)->toBe(1000.0)
        ->and($item->applied_promotion_id)->toBe($promotion->id);
});

// ---------------------------------------------------------------------------
// refundItem — dòng hoàn copy nguyên dấu vết của dòng gốc, không tính lại
// ---------------------------------------------------------------------------

it('copies the source line original_unit_price verbatim onto the refund line (promotion line)', function () {
    oupPromotion(15);
    $order = oupOrder();
    $sku = oupMenuSku(1000);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 2,
    ]]]);
    $source = $order->items()->firstOrFail();

    test()->service->refundItem($order->fresh(), (string) $source->id, 1.0, 'test-refund');

    $refund = $order->items()->where('refund_of_item_id', $source->id)->firstOrFail();
    expect((float) $refund->quantity)->toBe(-1.0)
        ->and((float) $refund->unit_price)->toBe(850.0)
        ->and((float) $refund->original_unit_price)->toBe(1000.0)
        // Dòng hoàn KHÔNG mang applied_promotion_id — nó không được phép lọt
        // vào hai reader báo cáo khuyến mãi (cả hai scope theo cột đó).
        ->and($refund->applied_promotion_id)->toBeNull();
});

it('copies original == unit onto the refund line of an undiscounted line', function () {
    $order = oupOrder();
    $sku = oupMenuSku(600);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 1,
    ]]]);
    $source = $order->items()->firstOrFail();

    test()->service->refundItem($order->fresh(), (string) $source->id, 1.0);

    $refund = $order->items()->where('refund_of_item_id', $source->id)->firstOrFail();
    expect($refund->original_unit_price)->not->toBeNull()
        ->and((float) $refund->original_unit_price)->toBe(600.0)
        ->and((float) $refund->unit_price)->toBe(600.0);
});

// ---------------------------------------------------------------------------
// Hai reader + snapshot: CÙNG một con số (họ lỗi #2074)
// ---------------------------------------------------------------------------

it('keeps promotion depth from the snapshot equal to both MenuPromotionService readers', function () {
    $promotion = oupPromotion(15);
    $order = oupOrder();
    $sku = oupMenuSku(1000);

    test()->service->addItems($order, ['items' => [[
        'product_sku_id' => $sku->id,
        'quantity' => 3,
    ]]]);

    // Reader 0 — phép cộng trực tiếp trên snapshot (bất biến của ruling):
    // Σ (original − unit) × qty trên các dòng đã áp promotion.
    $depthFromSnapshot = (float) CustomerOrderItem::query()
        ->where('applied_promotion_id', $promotion->id)
        ->get()
        ->sum(fn ($i) => ((float) $i->original_unit_price - (float) $i->unit_price) * (float) $i->quantity);
    expect($depthFromSnapshot)->toBe(450.0); // (1000 − 850) × 3

    // Reader 1 — HQ list withSum (MenuPromotionService::list with_report).
    $listRow = app(MenuPromotionService::class)
        ->list(['with_report' => true, 'branch_id' => test()->branch->id])
        ->items()[0];
    expect((float) $listRow->total_discount_amount)->toBe(450.0);

    // Reader 2 — summaryForPromotion (detail report).
    $summary = app(MenuPromotionService::class)->report($promotion);
    expect($summary['total_discount_applied'])->toBe(450.0);

    // Hoàn 1 đơn vị: dòng hoàn copy original nhưng KHÔNG mang
    // applied_promotion_id, nên cả ba con số phải đứng yên.
    $source = $order->items()->whereNull('refund_of_item_id')->firstOrFail();
    test()->service->refundItem($order->fresh(), (string) $source->id, 1.0);

    $depthAfterRefund = (float) CustomerOrderItem::query()
        ->where('applied_promotion_id', $promotion->id)
        ->get()
        ->sum(fn ($i) => ((float) $i->original_unit_price - (float) $i->unit_price) * (float) $i->quantity);
    $summaryAfter = app(MenuPromotionService::class)->report($promotion);

    expect($depthAfterRefund)->toBe(450.0)
        ->and($summaryAfter['total_discount_applied'])->toBe(450.0);
});

// ---------------------------------------------------------------------------
// Ghost line — sinh từ KDS bump cho dòng Cloud chưa từng thấy
// ---------------------------------------------------------------------------

it('stamps original_unit_price on a workstation ghost line', function () {
    $order = oupOrder();
    $sku = oupMenuSku(700);
    $ghostId = (string) Str::uuid();

    test()->service->ghostCreateWorkstationItem($order, $ghostId, [
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'unit_price' => 700,
    ]);

    $ghost = CustomerOrderItem::findOrFail($ghostId);
    expect($ghost->original_unit_price)->not->toBeNull()
        ->and((float) $ghost->original_unit_price)->toBe(700.0)
        ->and((float) $ghost->unit_price)->toBe(700.0);
});

// ---------------------------------------------------------------------------
// Workstation sync-UP — dòng mới sinh từ LAN replay
// ---------------------------------------------------------------------------

it('stamps a synced line without a client strikethrough at the authoritative unit price', function () {
    $order = oupOrder();
    $sku = oupMenuSku(800);
    $itemId = (string) Str::uuid();

    test()->service->syncWorkstationItems($order, [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'unit_price' => 800,
    ]], [0 => 800.0]);

    $item = CustomerOrderItem::findOrFail($itemId);
    expect($item->original_unit_price)->not->toBeNull()
        ->and((float) $item->original_unit_price)->toBe(800.0);
});

it('keeps the client strikethrough on a synced promotion line', function () {
    $order = oupOrder();
    $sku = oupMenuSku(1000);
    $itemId = (string) Str::uuid();

    test()->service->syncWorkstationItems($order, [[
        'id' => $itemId,
        'product_sku_id' => $sku->id,
        'quantity' => 1,
        'unit_price' => 850,
        'original_unit_price' => 1000,
    ]], [0 => 850.0]);

    $item = CustomerOrderItem::findOrFail($itemId);
    expect((float) $item->original_unit_price)->toBe(1000.0)
        ->and((float) $item->unit_price)->toBe(850.0);
});
