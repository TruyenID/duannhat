<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

use App\Omnify\Enums\ProductSkuInventoryModeEnum;

/**
 * #1567 — ảnh chụp một BIẾN THỂ SẢN PHẨM kèm công thức của nó, do Catalog công bố.
 *
 * Bảy trường đầu là toàn bộ những gì `ProductionCalculatorService` và
 * `ProductionOrderService` đọc — đo bằng cách quét thân method:
 *
 *     id ×2 · name ×2 · sku ×2 · productId ×2 · recipeMultiplier ×3
 *     product?->name (tên sản phẩm cha, dùng làm nhãn nhóm)
 *     recipe ×13
 *
 * `productName` là trường DẸT thay vì một `ProductSnapshot` lồng: chỗ gọi
 * chỉ đọc đúng cái tên, và một snapshot sản phẩm đầy đủ ở đây là mời người sau
 * với tay vào trường mà chưa ai đo.
 *
 * `recipe` null nghĩa là SKU không có công thức — **trạng thái hợp lệ**, không
 * phải lỗi: `ProductionOrderService` để `items` rỗng và `submit()` mới báo lỗi
 * "phải có ít nhất một dòng".
 *
 * ## `inventoryMode` — thêm ở #1731, và nó là NỬA THIẾU của cổng này
 *
 * Đây là trường quyết định một dòng đơn có sinh phiếu xuất kho SKU hay không
 * (`track_stock` ⇒ có; `made_to_order` ⇒ nguyên liệu đã tiêu ở khâu sản xuất
 * phía trên, chỉ ghi phả hệ). Thiếu nó thì `StockDeductionService` **vẫn phải
 * chạm `App\Models\ProductSku`** dù đã có `recipe` ở đây — tức cổng công bố rồi
 * mà cạnh không gỡ được. Không thêm "cho đủ bộ": nó được đo từ đúng một chỗ đọc
 * (`$item->productSku?->inventory_mode`), và chỗ đọc đó là lý do #1731 tồn tại.
 *
 * KHÔNG nullable: cột có `default('made_to_order')`, và chỗ gọi cũ so sánh
 * `=== 'track_stock'` nên mọi giá trị khác — kể cả null — vốn đã mang nghĩa
 * "không trừ kho SKU". Một `?enum` ở đây chỉ dựng lại thế ba trạng thái mà bản
 * cũ chưa từng có.
 */
final readonly class SkuSnapshot
{
    public function __construct(
        public string $id,
        public ?string $sku,
        public ?string $name,
        public ?string $productId,
        public ?string $productName,
        public float $recipeMultiplier,
        public ?RecipeSnapshot $recipe,
        public ProductSkuInventoryModeEnum $inventoryMode = ProductSkuInventoryModeEnum::MadeToOrder,
    ) {}

    /** Dòng đơn dùng SKU này có sinh phiếu xuất kho SKU không. */
    public function tracksStock(): bool
    {
        return $this->inventoryMode === ProductSkuInventoryModeEnum::TrackStock;
    }
}
