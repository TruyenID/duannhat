<?php

declare(strict_types=1);

/**
 * #962 · 7a-8 — đường ghi đơn thôi cầm `ProductSku` / `MenuProductSku` / `Menu`, và
 * 297 dòng luật topping về đúng module sở hữu bảng của nó.
 *
 * Nút này là phần còn lại mà #962 · 7a-7 (nút THUẾ) cố ý để lại: `$sku` đi qua trait
 * như một MODEL — vào `validateAndPriceToppings`, vào `isSellable()`, vào đường dự
 * phòng giá. Gỡ nó nghĩa là gỡ luôn `ToppingSelectionPricer`.
 *
 * Bài này ghim BA thứ khác nhau, và chỉ thứ nhất là "kiến trúc":
 *
 *  1. **Ranh giới** — bốn file Ordering không được import lại đám model đó, và
 *     `ToppingSelectionPricer` không được quay về `App\Services\Order\Internal`.
 *  2. **Neo dòng đơn** — từng mảnh truy vấn (`is_active`, phạm vi chi nhánh,
 *     `orderBy('selling_price')->orderBy('id')`, tầng 1 đọc qua QUAN HỆ) là một
 *     quyết định về TIỀN. Bỏ một mảnh thì ranh giới vẫn sạch mà hoá đơn vẫn sai.
 *  3. **Tiền topping** — hàng lưu giá ĐẦY ĐỦ, phần giảm sống ở mức DÒNG. Ghim lại
 *     vì bước chuyển mảng `['topping_subtotal', 'rows']` → DTO là đúng chỗ dễ
 *     "dọn" nhầm hai con số vào làm một.
 */

use App\Models\Branch;
use App\Models\Brand;
use App\Models\Menu;
use App\Models\MenuProduct;
use App\Models\MenuProductSku;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductSku;
use App\Models\ProductType;
use App\Models\TaxType;
use App\Models\ToppingGroup;
use App\Models\ToppingGroupItem;
use App\Models\ToppingGroupItemSku;
use App\Omnify\Enums\ProductStatusEnum;
use App\Services\Order\Contracts\OrderLineCatalogAnchors;
use App\Services\Order\Contracts\OrderToppingSelectionPricing;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// =============================================================================
//  1. Ranh giới
// =============================================================================

it('cả hai cổng đều bind được — cổng không có hiện thực là cổng trang trí', function () {
    expect(app(OrderLineCatalogAnchors::class))->toBeInstanceOf(OrderLineCatalogAnchors::class)
        ->and(app(OrderToppingSelectionPricing::class))->toBeInstanceOf(OrderToppingSelectionPricing::class);
});

it('bốn file Ordering KHÔNG còn import model catalog', function (string $relative, array $forbidden) {
    $src = (string) file_get_contents(app_path($relative));

    foreach ($forbidden as $import) {
        expect($src)->not->toContain("use {$import};", "{$relative} nhận lại cạnh {$import}");
    }
})->with([
    'trait ghi đơn' => [
        'Services/Order/Internal/Concerns/WritesCustomerOrders.php',
        ['App\Models\ProductSku', 'App\Models\MenuProductSku', 'App\Models\Menu', 'App\Models\ToppingGroupItem'],
    ],
    // Trait bị deptrac tính MỘT LẦN CHO MỖI class dùng nó, nên file này thừa hưởng
    // cạnh của trait mà không tự import gì. Ghim luôn để ai thêm import ở đây thì
    // biết mình đang mọc lại cạnh vừa gỡ.
    'persistence dùng trait' => [
        'Services/Order/Internal/EloquentOrderPersistence.php',
        ['App\Models\ProductSku', 'App\Models\MenuProductSku', 'App\Models\Menu', 'App\Models\ToppingGroupItem'],
    ],
    'resolver giá typed' => [
        'Services/Order/Internal/CustomerOrderPricingResolution.php',
        ['App\Models\ProductSku', 'App\Models\MenuProductSku', 'App\Models\Menu'],
    ],
    'adapter Catalog không được đi ngược' => [
        'Services/Menu/Internal/EloquentOrderLineCatalogAnchors.php',
        ['App\Models\CustomerOrder', 'App\Models\CustomerOrderItem'],
    ],
]);

it('ToppingSelectionPricer sống ở Catalog, không quay về Ordering', function () {
    // Chuyển cả class là QUYẾT ĐỊNH của gói này, không phải hệ quả phụ. Một PR sau
    // "gom lại cho gần chỗ dùng" sẽ kéo `ProductSku` + `ToppingGroupItem` về
    // Ordering nguyên si — và deptrac chỉ báo bằng một dòng baseline mới, thứ rất
    // dễ được duyệt qua.
    expect(file_exists(app_path('Services/Order/Internal/ToppingSelectionPricer.php')))->toBeFalse()
        ->and(file_exists(app_path('Services/Topping/Internal/ToppingSelectionPricer.php')))->toBeTrue();
});

it('cổng KHÔNG được nhận hay trả model — port rò model là cạnh cũ khoác áo interface', function (string $contract) {
    $src = (string) file_get_contents(app_path("Services/Order/Contracts/{$contract}.php"));

    expect($src)->not->toMatch('/\bApp\\\\Models\\\\/');
})->with([
    'OrderLineCatalogAnchors',
    'OrderLineSkuAnchor',
    'OrderLineMenuAnchor',
    'OrderToppingSelectionPricing',
    'PricedToppingSelection',
]);

// =============================================================================
//  2. Neo dòng đơn — từng mảnh truy vấn là một quyết định về TIỀN
// =============================================================================

describe('OrderLineCatalogAnchors', function () {
    beforeEach(function () {
        $this->orgId = (string) Str::uuid();
        Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
        $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $this->branch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
        ]);
        $this->otherBranch = Branch::factory()->create([
            'console_organization_id' => $this->orgId,
            'console_brand_id' => $this->brand->console_brand_id,
            'is_active' => true,
        ]);

        $this->standard = TaxType::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'code' => 'STANDARD', 'rate' => 10, 'is_default' => true,
        ]);
        $this->reduced = TaxType::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'code' => 'REDUCED', 'rate' => 8,
        ]);

        $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);
        $this->product = Product::factory()->active()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'product_type_id' => $pt->id, 'tax_type_id' => $this->reduced->id,
        ]);
        $this->sku = ProductSku::factory()->create([
            'product_id' => $this->product->id, 'is_active' => true, 'selling_price' => 500,
        ]);

        $this->menuLineOn = function (Branch $b, float $price, bool $isActive = true, ?string $lineTaxTypeId = null): MenuProductSku {
            $menu = Menu::factory()->create([
                'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
                'branch_id' => $b->id, 'status' => 'Active',
            ]);
            $menuProduct = MenuProduct::factory()->create([
                'menu_id' => $menu->id, 'product_id' => $this->product->id,
                'is_active' => true, 'tax_type_id' => $lineTaxTypeId,
            ]);

            return MenuProductSku::factory()->create([
                'menu_product_id' => $menuProduct->id,
                'product_sku_id' => $this->sku->id,
                'selling_price' => $price,
                'is_active' => $isActive,
            ]);
        };

        $this->anchors = app(OrderLineCatalogAnchors::class);
    });

    it('ảnh chụp SKU mang đủ giá, tenant và cờ bán được', function () {
        $anchor = $this->anchors->sku((string) $this->sku->id);

        expect($anchor)->not->toBeNull()
            ->and($anchor->skuId)->toBe((string) $this->sku->id)
            ->and($anchor->productId)->toBe((string) $this->product->id)
            ->and($anchor->productResolved)->toBeTrue()
            ->and($anchor->sellable)->toBeTrue()
            ->and($anchor->sellingPrice)->toBe(500.0)
            ->and($anchor->productTaxTypeId)->toBe((string) $this->reduced->id)
            ->and($anchor->productBrandId)->toBe((string) $this->brand->id)
            ->and($anchor->productOrganizationId)->toBe($this->orgId);
    });

    it('sản phẩm cha KHÔNG active ⇒ không bán được — #902, và luật đó thuộc Catalog', function () {
        // `isSellable()` = `is_active` VÀ sản phẩm cha `Active`. Nếu cổng chỉ trả
        // `is_active` rồi để Ordering tự ghép, một sản phẩm bị tạm dừng vẫn bán được.
        $this->product->update(['status' => ProductStatusEnum::Draft->value]);

        expect($this->anchors->sku((string) $this->sku->id)->sellable)->toBeFalse();
    });

    it('sản phẩm cha đã xoá mềm ⇒ productResolved = false dù cột product_id còn giá trị', function () {
        // Đây KHÔNG phải chi tiết vụn: `editItem` rẽ nhánh trên chính điều kiện này
        // để quyết định có đóng lại thuế lên dòng đơn hay không. Suy nó từ
        // `product_id !== null` sẽ đóng một tỉ lệ mới lên dòng của sản phẩm đã bị gỡ.
        $this->product->delete();

        $anchor = $this->anchors->sku((string) $this->sku->id);

        expect($anchor->productId)->toBe((string) $this->product->id)
            ->and($anchor->productResolved)->toBeFalse()
            ->and($anchor->sellable)->toBeFalse();
    });

    it('sku() trả null còn requireSku() ném 404 — hai hợp đồng lỗi, không gộp', function () {
        $ghost = (string) Str::uuid();

        expect($this->anchors->sku($ghost))->toBeNull();
        expect(fn () => $this->anchors->requireSku($ghost))->toThrow(ModelNotFoundException::class);
    });

    it('activeMenuLine trả giá + tầng 1 + tenant của đúng dòng menu đó', function () {
        $line = ($this->menuLineOn)($this->branch, 420.0, true, (string) $this->standard->id);

        $anchor = $this->anchors->activeMenuLine((string) $line->id, (string) $this->branch->id);

        expect($anchor)->not->toBeNull()
            ->and($anchor->menuProductSkuId)->toBe((string) $line->id)
            ->and($anchor->productSkuId)->toBe((string) $this->sku->id)
            ->and($anchor->sellingPrice)->toBe(420.0)
            ->and($anchor->taxTypeId)->toBe((string) $this->standard->id)
            ->and($anchor->brandId)->toBe((string) $this->brand->id)
            ->and($anchor->organizationId)->toBe($this->orgId)
            ->and($anchor->menuId)->not->toBeNull();
    });

    it('activeMenuLine BỎ QUA dòng đã tắt và menu của chi nhánh khác', function () {
        $off = ($this->menuLineOn)($this->branch, 420.0, false);
        $foreign = ($this->menuLineOn)($this->otherBranch, 420.0);

        expect($this->anchors->activeMenuLine((string) $off->id, (string) $this->branch->id))->toBeNull()
            ->and($this->anchors->activeMenuLine((string) $foreign->id, (string) $this->branch->id))->toBeNull();
    });

    it('activeMenuLine với productSkuId khác ⇒ null — client không được để hai id lệch nhau', function () {
        // `addItems` gửi CẢ `product_sku_id` lẫn `menu_product_sku_id`. Bỏ điều kiện
        // đối chiếu là cho phép tính tiền món A theo dòng menu của món B.
        $line = ($this->menuLineOn)($this->branch, 420.0);
        // SKU của một sản phẩm KHÁC: `product_skus` là UNIQUE trên
        // (product_id, option_signature), nên một sản phẩm không có option chỉ ôm
        // được đúng một SKU.
        $otherProduct = Product::factory()->active()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'product_type_id' => $this->product->product_type_id,
        ]);
        $otherSku = ProductSku::factory()->create(['product_id' => $otherProduct->id, 'is_active' => true]);

        expect($this->anchors->activeMenuLine((string) $line->id, (string) $this->branch->id, (string) $otherSku->id))
            ->toBeNull()
            ->and($this->anchors->activeMenuLine((string) $line->id, (string) $this->branch->id, (string) $this->sku->id))
            ->not->toBeNull();
    });

    it('TaxType đã xoá mềm ⇒ tầng 1 rỗng, chuỗi tầng đi tiếp', function () {
        // Lối cũ đọc quan hệ `taxType` (có `SoftDeletingScope`), không đọc cột. Hiện
        // thực nào "tối ưu" thành đọc thẳng `tax_type_id` sẽ đóng dấu một tỉ lệ chết.
        $line = ($this->menuLineOn)($this->branch, 420.0, true, (string) $this->standard->id);
        $this->standard->delete();

        $anchor = $this->anchors->activeMenuLine((string) $line->id, (string) $this->branch->id);

        expect($anchor)->not->toBeNull()
            ->and($anchor->taxTypeId)->toBeNull()
            ->and($anchor->menuId)->not->toBeNull();
    });

    it('#514: cheapestActiveMenuLine lấy giá THẤP NHẤT, hoà thì theo id — và ổn định', function () {
        // ORD-2026-4216: khách trả 3.471đ trên một đơn ghi 3.667đ, vì truy vấn cũ
        // không có `orderBy` và DB trả hàng nào tuỳ hứng.
        ($this->menuLineOn)($this->branch, 900.0);
        $cheap = ($this->menuLineOn)($this->branch, 300.0);
        ($this->menuLineOn)($this->branch, 600.0);

        $first = $this->anchors->cheapestActiveMenuLine((string) $this->branch->id, (string) $this->sku->id);

        expect($first->menuProductSkuId)->toBe((string) $cheap->id)
            ->and($first->sellingPrice)->toBe(300.0)
            // Gọi lại phải ra y hệt: một đơn re-resolve hai lần không được ra hai giá.
            ->and($this->anchors->cheapestActiveMenuLine((string) $this->branch->id, (string) $this->sku->id)->menuProductSkuId)
            ->toBe((string) $cheap->id);
    });

    it('#514: hai dòng CÙNG giá thì chốt theo id, không theo thứ tự DB trả', function () {
        $lines = collect([
            ($this->menuLineOn)($this->branch, 500.0),
            ($this->menuLineOn)($this->branch, 500.0),
        ]);
        $expected = $lines->sortBy(fn (MenuProductSku $l): string => (string) $l->id)->first();

        expect($this->anchors->cheapestActiveMenuLine((string) $this->branch->id, (string) $this->sku->id)->menuProductSkuId)
            ->toBe((string) $expected->id);
    });

    it('cheapestActiveMenuLine BỎ QUA dòng tắt + menu chi nhánh khác, không có gì thì null', function () {
        ($this->menuLineOn)($this->branch, 100.0, false);
        ($this->menuLineOn)($this->otherBranch, 100.0);

        expect($this->anchors->cheapestActiveMenuLine((string) $this->branch->id, (string) $this->sku->id))->toBeNull();
    });

    it('hai phép tra thô của đường persist: SKU của dòng menu, và brand của menu', function () {
        $line = ($this->menuLineOn)($this->branch, 420.0);
        $menuId = (string) MenuProduct::query()->whereKey($line->menu_product_id)->value('menu_id');
        $ghost = (string) Str::uuid();

        expect($this->anchors->requireProductSkuIdForMenuLine((string) $line->id))->toBe((string) $this->sku->id)
            ->and($this->anchors->brandIdForMenu($menuId))->toBe((string) $this->brand->id)
            ->and($this->anchors->brandIdForMenu($ghost))->toBeNull();

        expect(fn () => $this->anchors->requireProductSkuIdForMenuLine($ghost))
            ->toThrow(ModelNotFoundException::class);
    });

    it('requireProductSkuIdForMenuLine KHÔNG lọc is_active — chỗ gọi cũ cũng không', function () {
        // Đường `continueTableSession` chỉ dịch id sang id trên một đơn ĐÃ BÁN.
        // Thêm phạm vi vào đây là đổi hành vi kèm theo một PR ranh giới.
        $off = ($this->menuLineOn)($this->branch, 420.0, false);

        expect($this->anchors->requireProductSkuIdForMenuLine((string) $off->id))->toBe((string) $this->sku->id);
    });
});

// =============================================================================
//  3. Tiền topping — hàng giữ giá ĐẦY ĐỦ, phần giảm ở mức DÒNG
// =============================================================================

describe('OrderToppingSelectionPricing', function () {
    beforeEach(function () {
        $this->orgId = (string) Str::uuid();
        Organization::factory()->create(['id' => $this->orgId, 'console_organization_id' => $this->orgId]);
        $this->brand = Brand::factory()->create(['console_organization_id' => $this->orgId]);
        $pt = ProductType::factory()->create(['organization_id' => $this->orgId, 'brand_id' => $this->brand->id]);

        $this->product = Product::factory()->active()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id, 'product_type_id' => $pt->id,
        ]);
        $this->sku = ProductSku::factory()->create(['product_id' => $this->product->id, 'is_active' => true]);

        $this->pricing = app(OrderToppingSelectionPricing::class);

        /** Một item topping có giá, thuộc `$group`. */
        $this->itemWithPrice = function (ToppingGroup $group, float $price, bool $isDefault = false, int $sortOrder = 0) {
            $toppingProduct = Product::factory()->active()->create([
                'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
                'product_type_id' => $this->product->product_type_id,
            ]);
            $toppingSku = ProductSku::factory()->create(['product_id' => $toppingProduct->id, 'is_active' => true]);
            $item = ToppingGroupItem::factory()->create([
                'topping_group_id' => $group->id,
                'product_id' => $toppingProduct->id,
                'is_default' => $isDefault,
                'sort_order' => $sortOrder,
            ]);
            ToppingGroupItemSku::factory()->create([
                'topping_group_item_id' => $item->id,
                'product_sku_id' => $toppingSku->id,
                'extra_price' => $price,
            ]);

            return [$item, $toppingSku];
        };
    });

    it('nhóm free_up_to_n: HÀNG giữ giá đầy đủ, phần miễn phí chỉ trừ ở subtotal', function () {
        // DESIGN.md plan-015 Decision 6. `order_item_toppings.unit_price` là ảnh
        // chụp giá THẬT của topping; phần giảm nằm ở `topping_subtotal` của DÒNG.
        // Trộn hai con số vào làm một là ghi sai bảng snapshot — và không có gì
        // đối chiếu lại sau đó.
        $group = ToppingGroup::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'is_active' => true, 'min_select' => 0,
            'price_strategy' => 'free_up_to_n', 'free_quantity' => 1,
        ]);
        $this->product->toppingGroups()->attach($group->id, ['sort_order' => 1]);

        [$a, $aSku] = ($this->itemWithPrice)($group, 100.0);
        [$b, $bSku] = ($this->itemWithPrice)($group, 60.0);

        $result = $this->pricing->priceForSku((string) $this->sku->id, [
            ['topping_group_item_id' => (string) $a->id, 'product_sku_id' => (string) $aSku->id, 'quantity' => 1],
            ['topping_group_item_id' => (string) $b->id, 'product_sku_id' => (string) $bSku->id, 'quantity' => 1],
        ]);

        // Đơn vị đắt nhất được miễn (100), còn lại 60.
        expect($result->subtotal)->toBe(60.0)
            ->and($result->rows)->toHaveCount(2)
            ->and(collect($result->rows)->sum('unit_price'))->toBe(160.0);
    });

    it('nhóm bắt buộc bị bỏ trống ⇒ tự điền item is_default, và ĐƯỢC TÍNH TIỀN', function () {
        // Khách không chọn gì, nhưng nhóm min_select ≥ 1 vẫn phải ra một dòng
        // topping có thật — nếu không, bill và bếp nhìn hai món khác nhau.
        $group = ToppingGroup::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'is_active' => true, 'min_select' => 1, 'price_strategy' => 'flat',
        ]);
        $this->product->toppingGroups()->attach($group->id, ['sort_order' => 1]);
        [$def] = ($this->itemWithPrice)($group, 80.0, isDefault: true);

        $result = $this->pricing->priceForSku((string) $this->sku->id, []);

        expect($result->rows)->toHaveCount(1)
            ->and($result->rows[0]['topping_group_item_id'])->toBe((string) $def->id)
            ->and($result->rows[0]['quantity'])->toBe(1)
            ->and($result->subtotal)->toBe(80.0);
    });

    it('nhóm bắt buộc không có default và không phải combo ⇒ từ chối, không âm thầm bỏ qua', function () {
        $group = ToppingGroup::factory()->create([
            'organization_id' => $this->orgId, 'brand_id' => $this->brand->id,
            'is_active' => true, 'min_select' => 1, 'price_strategy' => 'flat',
        ]);
        $this->product->toppingGroups()->attach($group->id, ['sort_order' => 1]);
        ($this->itemWithPrice)($group, 80.0);

        expect(fn () => $this->pricing->priceForSku((string) $this->sku->id, []))
            ->toThrow(ValidationException::class);
    });

    it('không chọn gì và không nhóm nào bắt buộc ⇒ 0, không phải lỗi', function () {
        $result = $this->pricing->priceForSku((string) $this->sku->id, []);

        expect($result->subtotal)->toBe(0.0)
            ->and($result->rows)->toBe([]);
    });

    it('cổng nhận ID: SKU không tồn tại thì từ chối có cấu trúc, không nổ null', function () {
        // Chỗ gọi cũ truyền model đã nạp sẵn nên nhánh này không thể tới. Nó tồn tại
        // vì cổng nhận id — và nếu ai đó bỏ nhánh này thì một id sai sẽ đi tiếp vào
        // `$sku->product()` và nổ thành 500 không đọc được.
        expect(fn () => $this->pricing->priceForSku((string) Str::uuid(), []))
            ->toThrow(ValidationException::class);
    });
});
