<?php

declare(strict_types=1);

namespace App\Services\Menu\Internal;

use App\Models\Menu;
use App\Models\MenuProductSku;
use App\Models\ProductSku;
use App\Services\Order\Contracts\OrderLineCatalogAnchors;
use App\Services\Order\Contracts\OrderLineMenuAnchor;
use App\Services\Order\Contracts\OrderLineSkuAnchor;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * #962 · 7a-8 — Catalog hiện thực cổng neo dòng đơn mà Ordering khai.
 *
 * Sáu truy vấn dưới đây được **chép nguyên** từ `WritesCustomerOrders::addItems`,
 * `::editItem`, `CustomerOrderPricingResolution::anchorForSelection` và
 * `EloquentOrderPersistence`, cố ý không "dọn" gì. Mỗi điều kiện đổi ở đây là đổi
 * **giá tính lên đơn**, không phải đổi kiểu code.
 *
 * ## Ba chỗ lệch so với bản cũ, và vì sao chúng an toàn
 *
 * 1. **`with('product')` thay cho `with('product.taxType')`.** Lối cũ nạp cả quan hệ
 *    `taxType` để `TaxResolver` đọc model; từ #962 · 7a-7 cổng thuế chỉ nhận **id**,
 *    nên nhánh nạp đó đã thành thừa. `product` thì vẫn phải nạp: `isSellable()` đọc
 *    `$this->product?->status` và docblock của nó nói thẳng *"a null product is
 *    treated as NOT sellable"*.
 * 2. **Nhánh dự phòng #514 nay chọn cả hàng thay vì `first(['selling_price',
 *    'menu_product_id'])`.** Cùng hàng, cùng thứ tự — chỉ là hai chỗ gọi cũ cần hai
 *    tập cột khác nhau và cổng trả một hình dạng chung.
 * 3. **`activeMenuLine` nạp thêm `menuProduct.menu`.** Đường `addItems` không cần
 *    tenant nhưng đường typed thì cần; nạp sẵn ở đây rẻ hơn để chỗ gọi tự hỏi lại.
 *
 * ## `taxType` phải là QUAN HỆ, không được "tối ưu" thành cột
 *
 * `->menuProduct?->taxType?->id` đi qua `SoftDeletingScope`, nên một `TaxType` đã
 * xoá mềm làm tầng 1 rỗng và chuỗi tầng đi tiếp. Đọc thẳng `tax_type_id` giữ lại
 * type đã chết và đóng dấu tỉ lệ của nó lên đơn — hỏng lúc chạy, không hỏng lúc
 * biên dịch, và không có gì đối chiếu lại vì tỉ lệ là snapshot bất biến.
 */
final class EloquentOrderLineCatalogAnchors implements OrderLineCatalogAnchors
{
    public function sku(string $productSkuId): ?OrderLineSkuAnchor
    {
        $sku = ProductSku::query()->with('product')->find($productSkuId);

        return $sku === null ? null : $this->toSkuAnchor($sku);
    }

    public function requireSku(string $productSkuId): OrderLineSkuAnchor
    {
        return $this->toSkuAnchor(
            ProductSku::query()->with('product')->findOrFail($productSkuId),
        );
    }

    public function activeMenuLine(string $menuProductSkuId, string $branchId, ?string $productSkuId = null): ?OrderLineMenuAnchor
    {
        $line = MenuProductSku::query()
            ->whereKey($menuProductSkuId)
            ->when($productSkuId !== null, fn ($q) => $q->where('product_sku_id', $productSkuId))
            ->where('is_active', true)
            ->whereHas('menuProduct.menu', fn ($q) => $q->where('branch_id', $branchId))
            ->with(['menuProduct.taxType', 'menuProduct.menu:id,brand_id,organization_id'])
            ->first();

        return $line === null ? null : $this->toMenuAnchor($line);
    }

    public function cheapestActiveMenuLine(string $branchId, string $productSkuId): ?OrderLineMenuAnchor
    {
        $line = MenuProductSku::query()
            ->where('product_sku_id', $productSkuId)
            ->where('is_active', true)
            ->whereHas('menuProduct.menu', fn ($q) => $q->where('branch_id', $branchId))
            ->with(['menuProduct.taxType', 'menuProduct.menu:id,brand_id,organization_id'])
            ->orderBy('selling_price')
            ->orderBy('id')
            ->first();

        return $line === null ? null : $this->toMenuAnchor($line);
    }

    public function menuLine(string $menuProductSkuId): ?OrderLineMenuAnchor
    {
        $line = MenuProductSku::query()
            ->whereKey($menuProductSkuId)
            ->with(['menuProduct.taxType', 'menuProduct.menu:id,brand_id,organization_id'])
            ->first();

        return $line === null ? null : $this->toMenuAnchor($line);
    }

    public function requireProductSkuIdForMenuLine(string $menuProductSkuId): string
    {
        $productSkuId = MenuProductSku::query()
            ->whereKey($menuProductSkuId)
            ->value('product_sku_id');

        if ($productSkuId === null) {
            throw (new ModelNotFoundException)->setModel(MenuProductSku::class, [$menuProductSkuId]);
        }

        return (string) $productSkuId;
    }

    public function brandIdForMenu(string $menuId): ?string
    {
        $brandId = Menu::query()->whereKey($menuId)->value('brand_id');

        return $brandId === null ? null : (string) $brandId;
    }

    private function toSkuAnchor(ProductSku $sku): OrderLineSkuAnchor
    {
        $product = $sku->product;

        return new OrderLineSkuAnchor(
            skuId: (string) $sku->id,
            productId: $sku->product_id === null ? null : (string) $sku->product_id,
            productResolved: $product !== null,
            sellable: $sku->isSellable(),
            sellingPrice: (float) $sku->selling_price,
            productTaxTypeId: $product?->tax_type_id === null ? null : (string) $product->tax_type_id,
            productBrandId: $product?->brand_id === null ? null : (string) $product->brand_id,
            productOrganizationId: $product?->organization_id === null ? null : (string) $product->organization_id,
        );
    }

    private function toMenuAnchor(MenuProductSku $line): OrderLineMenuAnchor
    {
        $menuProduct = $line->menuProduct;
        $menu = $menuProduct?->menu;

        return new OrderLineMenuAnchor(
            menuProductSkuId: (string) $line->id,
            productSkuId: (string) $line->product_sku_id,
            menuProductId: $line->menu_product_id === null ? null : (string) $line->menu_product_id,
            menuId: $menu?->id === null ? null : (string) $menu->id,
            menuSectionId: $menuProduct?->menu_section_id === null ? null : (string) $menuProduct->menu_section_id,
            brandId: $menu?->brand_id === null ? null : (string) $menu->brand_id,
            organizationId: $menu?->organization_id === null ? null : (string) $menu->organization_id,
            sellingPrice: $line->selling_price === null ? null : (float) $line->selling_price,
            taxTypeId: $menuProduct?->taxType?->id === null ? null : (string) $menuProduct->taxType->id,
        );
    }
}
