<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 · 7a-7 — ba giá trị Ordering cần từ MỘT dòng menu để đóng thuế lên dòng đơn.
 *
 * Toàn scalar, không model: hợp đồng nằm ở layer `PublishedContracts`, mà layer đó
 * **không được phụ thuộc module nào**. Một cổng mang theo `MenuProduct` hay `TaxType`
 * sẽ đỏ ngay ở deptrac — đó chính là bản máy của luật "port không được rò model".
 *
 * ## Ba trường này là BA TẦNG KHÁC NHAU của plan-043, đừng gộp
 *
 * | trường | tầng | ý nghĩa |
 * |---|---|---|
 * | `taxTypeId` | 1 | override của CHÍNH dòng menu (`menu_products.tax_type_id`) |
 * | `menuSectionId` | 2 | section trong menu đó — giá trị nằm ở PIVOT `menu_menu_sections` |
 * | `menuId` | 3 | cả menu (menu 持ち帰り là 8%) |
 *
 * Người gọi chỉ chuyển tiếp cả ba xuống {@see OrderLineTaxBatch}; **không** được tự
 * chọn tầng nào thắng ở phía Ordering — thứ tự tầng có đúng một định nghĩa và nó
 * nằm trong Pricing.
 *
 * `menuId`/`menuSectionId` là **id** chứ không phải giá trị thuế: tầng 2 và 3 được
 * Pricing tra ngược từ id (và memo hoá), nên Catalog không phải biết bảng thuế.
 */
final readonly class OrderMenuLineTaxContext
{
    public function __construct(
        public ?string $menuId,
        public ?string $menuSectionId,
        public ?string $taxTypeId,
    ) {}

    /**
     * Không có dòng menu nào — resolver bỏ qua tầng 1-3 và rơi thẳng xuống sản phẩm.
     * Đây là hành vi trước #1218 và vẫn là hành vi đúng cho đơn off-menu.
     */
    public static function none(): self
    {
        return new self(null, null, null);
    }
}
