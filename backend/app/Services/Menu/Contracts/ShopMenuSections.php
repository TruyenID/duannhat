<?php

declare(strict_types=1);

namespace App\Services\Menu\Contracts;

/**
 * #1622 — cổng Catalog công bố: **mục menu (section) mà MỘT CỬA HÀNG đang bày**.
 *
 * Báo cáo doanh thu POS cần biết cửa hàng có những mục nào để dựng dropdown
 * "Danh mục" và gắn nhãn mục cho từng dòng doanh thu. Trước bản vá này nó tự
 * đọc thẳng `menus` + `menu_sections` + `menu_menu_sections` — bảng của Catalog
 * — bằng query builder thô, nên deptrac không thấy (không import class nào) và
 * `architecture:raw-table-reads` mãi tới #1625 mới đếm được (hai truy vấn đặt
 * bí danh).
 *
 * ## Vì sao là bốn method chứ không phải một `sections()`
 *
 * Bốn câu hỏi khác nhau, và ba trong số đó có **ngữ nghĩa dễ nhầm**:
 *
 * - `menuIdsForShop` — cửa hàng thường có 1 menu ghim theo chi nhánh **cộng**
 *   menu chung của thương hiệu; cả hai đều tính.
 * - `sectionsForShop` — danh sách cho dropdown, **gộp theo TÊN** (menu chung và
 *   menu riêng thường đặt trùng tên mục nhưng khác id), lấy id nhỏ nhất làm
 *   đại diện.
 * - `sectionIdsSharingName` — chiều NGƯỢC lại của việc gộp trên: người dùng
 *   chọn một id đại diện, và bộ lọc phải nở ra **mọi id cùng tên** trong menu
 *   của cửa hàng, nếu không món gắn vào mục "Main" của menu kia bị bỏ sót.
 * - `sectionName` — nhãn để đè lên kết quả khi người dùng đã lọc.
 *
 * Gộp chúng thành một method "linh hoạt" sẽ biến sự khác nhau giữa **gộp** và
 * **nở ra** thành một tham số boolean — và đảo nhầm nó thì báo cáo thiếu món mà
 * không có gì đỏ lên.
 */
interface ShopMenuSections
{
    /**
     * Id các menu mà cửa hàng này bày: menu ghim theo chi nhánh + menu chung
     * của thương hiệu (khi có `$brandId`).
     *
     * @return list<string>
     */
    public function menuIdsForShop(string $branchId, ?string $brandId): array;

    /**
     * Mục menu cho dropdown, **đã gộp theo tên**, sắp theo tên.
     *
     * @return list<array{id: string, name: string}>
     */
    public function sectionsForShop(string $branchId, ?string $brandId): array;

    /** Tên hiển thị của một mục; `null` khi id không tồn tại. */
    public function sectionName(string $sectionId): ?string;

    /**
     * Mọi id mục **cùng tên** với `$sectionId` trong các menu đã cho.
     *
     * Trả về chính `[$sectionId]` khi không tra được tên hoặc không có menu nào
     * — giữ nguyên hành vi cũ: bộ lọc thu hẹp về đúng mục người dùng chọn chứ
     * không mở rộng thành "tất cả".
     *
     * @param  list<string>  $menuIds
     * @return list<string>
     */
    public function sectionIdsSharingName(string $sectionId, array $menuIds): array;
}
