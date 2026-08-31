<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 · 7a-8 — DÒNG MENU mà một dòng đơn neo vào: giá của nó, và bốn id định danh.
 *
 * Khác {@see OrderMenuLineTaxContext} (#962 · 7a-7) một cách có chủ ý. Cái kia trả
 * lời "dòng menu này khai THUẾ gì" và được tra từ `menu_products`. Cái này là kết
 * quả tra **`menu_product_skus`** — hàng mà GIÁ đến từ đó — và mang theo cả tenant.
 * Hai bảng, hai câu hỏi; gộp lại là mở đúng vết nứt #1180: hiển thị theo hàng này,
 * tính tiền theo hàng khác.
 *
 * ## `sellingPrice` để `null` được, dù cột hiện là NOT NULL
 *
 * `$menuPrice ?? $sku->selling_price` là bước dự phòng có thật trong `addItems`.
 * Đo lại trên schema hiện tại: `menu_product_skus.selling_price` **NOT NULL**, nên
 * nhánh đó không tới được — cái `?` ở lối cũ là để bọc một MODEL null, không phải
 * một cột null. Vẫn giữ `?float` chứ không ép `0.0`: nếu cột được nới ra sau này,
 * một giá 0 im lặng nghĩa là bán không công, còn `null` thì rơi đúng về giá gốc.
 *
 * ## `taxTypeId` đọc từ QUAN HỆ, không phải cột
 *
 * Đây là override tầng 1 (`menu_products.tax_type_id`) và lối cũ đọc
 * `$menuProductSku->menuProduct->taxType?->id` — tức đi qua `SoftDeletingScope`.
 * Một `TaxType` đã xoá mềm phải làm tầng 1 rỗng để chuỗi tầng đi tiếp; đọc thẳng
 * cột sẽ giữ lại một type đã chết và đóng dấu tỉ lệ của nó lên đơn. Cùng cái bẫy
 * mà `OrderingTaxBoundaryPortsTest` đã ghim cho `OrderMenuLineDirectory`.
 *
 * `brandId`/`organizationId`/`menuId` lấy từ QUAN HỆ `menu_products.menu` — cùng chỗ
 * mà `CustomerOrderPricingResolution` neo tenant. Ba phép tra có phạm vi chi nhánh
 * (`activeMenuLine`, `cheapestActiveMenuLine`) `whereHas` qua `menuProduct.menu` nên
 * đã tìm thấy hàng là có đủ. `menuLine()` thì KHÔNG — nó cố ý không lọc gì — nên một
 * menu đã xoá mềm làm cả ba trường đó null trong khi cột `menu_products.menu_id` vẫn
 * còn giá trị. Đó là hành vi đúng cho chỗ gọi duy nhất của nó: nếu cần ID THUẾ của
 * tầng 2/3 thì hỏi {@see OrderMenuLineDirectory::taxContextForMenuProduct()}, cổng
 * đọc thẳng cột và là cổng mà đường bán ONLINE cũng dùng.
 */
final readonly class OrderLineMenuAnchor
{
    public function __construct(
        public string $menuProductSkuId,
        public string $productSkuId,
        public ?string $menuProductId,
        public ?string $menuId,
        public ?string $menuSectionId,
        public ?string $brandId,
        public ?string $organizationId,
        public ?float $sellingPrice,
        public ?string $taxTypeId,
    ) {}
}
