<?php

declare(strict_types=1);

namespace App\Services\Tax\Contracts;

/**
 * #962 — Pricing hỏi Catalog "MENU này, và MỤC này TRONG menu này, neo vào loại
 * thuế nào" (tầng 3 và tầng 2 của chuỗi #1218).
 *
 * ## Cổng này KHÔNG nhân đôi chuỗi tầng
 *
 * Chuỗi `MenuProduct → MenuMenuSection → Menu → Product → chi nhánh → brand` có
 * đúng MỘT định nghĩa và nó nằm ở `TaxResolver::walk()`. Cái chuyển sang đây chỉ
 * là hai PHÉP TRA nguyên liệu — "đọc `menus.tax_type_id`" và "đọc
 * `menu_menu_sections.tax_type_id`" — đúng như tầng 5 đã đi qua
 * `App\Services\Order\Contracts\BranchDefaultTaxType` từ trước (viết bằng backtick
 * CHỦ Ý: `{@see}` sẽ bị `pint` kéo thành `use` thật, và một `PublishedContracts`
 * phụ thuộc `PublishedContracts` khác là ĐỎ ngay tại rào). Thứ tự tầng không được
 * phép rời khỏi Pricing; nếu một PR sau thấy mình đang so sánh hai tầng bên trong
 * hiện thực của cổng này thì PR đó đang tạo động cơ thuế thứ hai.
 *
 * ## Trả về ID, không trả về `TaxType`
 *
 * `App\Models\TaxType` thuộc Pricing, và `PublishedContracts` không được phụ thuộc
 * module nào — một cổng mang model đó trong chữ ký sẽ đỏ ngay tại rào. Trả id còn
 * đúng về sở hữu: Catalog biết menu TRỎ tới loại thuế nào, Pricing mới là bên biết
 * loại thuế đó nghĩa là gì. Cùng lập luận đã chốt ở `BranchDefaultTaxType`.
 *
 * ## Hiện thực PHẢI đọc qua QUAN HỆ `taxType`, không đọc thẳng cột
 *
 * `TaxType` xoá mềm. Lối cũ (`->with('taxType')->first()?->taxType`) đi qua
 * `SoftDeletingScope`, nên một loại thuế đã xoá làm tầng đó RỖNG và chuỗi tầng đi
 * tiếp xuống sản phẩm. Đọc thẳng `tax_type_id` sẽ giữ lại một type đã chết và đóng
 * dấu tỉ lệ của nó lên đơn — hỏng lúc chạy, không hỏng lúc biên dịch, và tỉ lệ là
 * snapshot bất biến nên không có gì đối chiếu lại. Cùng cái bẫy mà
 * `OrderingCatalogAnchorPortsTest` đã ghim cho `OrderLineMenuAnchor`.
 *
 * ## Hai phép tra, không gộp làm một
 *
 * Tầng 2 nằm trên PIVOT `menu_menu_sections`, không nằm trên `menu_sections`: một
 * mục được bày trong nhiều menu, nên một cột đặt trên chính mục đó sẽ rò tỉ lệ của
 * menu này sang mọi menu khác đang bày nó (#1218). Vì thế `taxTypeIdForMenuSection`
 * BẮT BUỘC nhận cả `$menuId` — bỏ nó đi là biến tầng 2 thành thuộc tính toàn cục
 * của mục.
 */
interface MenuTaxTypeAnchors
{
    /**
     * Tầng 3 — loại thuế của CẢ menu (menu 持ち帰り là 8%).
     *
     * `null` = menu không tồn tại, đã xoá mềm, không khai loại thuế, hoặc khai một
     * loại đã bị xoá mềm. Bốn ca đó chỉ có một hệ quả với chuỗi tầng — đi tiếp —
     * nên cổng cố ý không phân biệt chúng.
     */
    public function taxTypeIdForMenu(string $menuId): ?string;

    /**
     * Tầng 2 — loại thuế mà MỘT MỤC khai TRONG MỘT MENU cụ thể (giá trị nằm trên
     * pivot `menu_menu_sections`).
     *
     * `null` = cặp (menu, mục) đó không có hàng pivot, không khai loại thuế, hoặc
     * khai một loại đã xoá mềm.
     */
    public function taxTypeIdForMenuSection(string $menuId, string $menuSectionId): ?string;
}
