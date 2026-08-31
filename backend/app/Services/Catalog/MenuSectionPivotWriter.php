<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\Menu;

/**
 * #1661 — chỗ ghi DUY NHẤT của `menu_menu_sections`, và là chỗ đánh dấu catalog.
 *
 * ## Vì sao không phải là một observer
 *
 * `CatalogRevisionObserver` cố ý là **model observer** — docblock của nó nói rõ
 * lý do: bản catalog phải phản ánh MỌI người ghi, kể cả seeder, lệnh console và
 * endpoint chưa ai viết. Nhưng bảng này không thể dùng observer:
 *
 *  - khoá chính là **kép** (`menu_id` + `menu_section_id`), không có cột đơn nào;
 *  - vì thế `belongsToMany` ghi bằng **query builder**, và query builder không
 *    phát sự kiện model;
 *  - và #1657 còn dựng hẳn một rào (`RefusesKeylessWrites`) **cấm** ghi qua
 *    model, vì `save()` trên nó chạy `WHERE id IS NULL` rồi báo thành công.
 *
 * #1218 vẫn đăng ký `MenuMenuSection::observe(...)` kèm comment nói nó chặn được
 * rò thuế. Dòng đó **không làm gì cả** — hai lý do độc lập, mỗi lý do đủ để giết
 * nó: observer không có nhánh nào cho model đó, và không sự kiện nào bắn ra.
 *
 * ## Vì sao là MỘT class chứ không phải chín lời gọi `markDirty`
 *
 * Có **chín** chỗ ghi pivot này (`MenuService` ×8, `MenuSectionService` ×1).
 * Rắc `markDirty` vào từng chỗ là đúng loại việc mà docblock của observer nói
 * thẳng là nó tồn tại để KHỎI phải làm: người thứ mười sẽ quên. Gom về một chỗ
 * rồi **cấm đường đi tắt bằng test kiến trúc**
 * (`tests/Feature/Architecture/MenuSectionPivotWritesAreCentralisedTest.php`)
 * là thứ gần nhất với một observer mà bảng này cho phép.
 *
 * Tên test viết dạng ĐƯỜNG DẪN chứ không `{@see}`: pint biến `{@see}` thành một
 * `use` THẬT, và ở đây nó sẽ import một class test vào code sản xuất.
 *
 * ## Vì sao đây là lỗi tiền
 *
 * `menu_menu_sections.tax_type_id` là **tầng 2** của chuỗi phân giải thuế
 * (#1218), và feed menu của workstation gộp bốn tầng đầu vào một cột
 * `menu_items.tax_type_id`. Phiên bản của feed đó là `'rev-'.$catalogRevision`,
 * nên revision không tiến ⇒ thiết bị nhận **304** ⇒ nó in hoá đơn theo thuế suất
 * cũ trong khi Cloud ghi sổ theo thuế suất mới.
 */
final class MenuSectionPivotWriter
{
    public function __construct(private readonly CatalogRevisionService $revisions) {}

    /**
     * @param  array<string, array<string, mixed>>  $pivotData  menu_section_id => cột pivot
     * @return array{attached: list<mixed>, detached: list<mixed>, updated: list<mixed>}
     */
    public function sync(Menu $menu, array $pivotData): array
    {
        $result = $menu->menuSections()->sync($pivotData);

        $this->markDirty($menu);

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $pivotData
     */
    public function attach(Menu $menu, array $pivotData): void
    {
        if ($pivotData === []) {
            return;
        }

        $menu->menuSections()->attach($pivotData);

        $this->markDirty($menu);
    }

    /**
     * Gỡ liên kết menu↔section. KHÔNG xoá `menu_sections` — một section được
     * nhiều menu dùng chung (chính vì thế tầng thuế nằm ở pivot), nên xoá hàng
     * section để dọn một menu sẽ rút nó khỏi mọi menu khác.
     *
     * @param  list<string>  $menuSectionIds
     */
    public function detach(Menu $menu, array $menuSectionIds): void
    {
        if ($menuSectionIds === []) {
            return;
        }

        $menu->menuSections()->detach($menuSectionIds);

        $this->markDirty($menu);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateExistingPivot(Menu $menu, string $menuSectionId, array $attributes): void
    {
        $menu->menuSections()->updateExistingPivot($menuSectionId, $attributes);

        $this->markDirty($menu);
    }

    /**
     * Đánh dấu VÔ ĐIỀU KIỆN, kể cả khi chỉ đổi `display_order`.
     *
     * Không lọc "chỉ đánh dấu khi `tax_type_id` đổi": `bumpFor()` đã tự quyết
     * việc đó đúng hơn — nó so **hash của cả bản đồ giá** (BR-CR02), nên một
     * thay đổi thuần hiển thị không mint bản mới dù có đánh dấu. Lọc ở đây là
     * dựng một luật "cái gì đáng kể" thứ hai, và luật thứ hai luôn là luật sẽ
     * trôi lệch.
     *
     * `markDirty(null)` là no-op, nên menu HQ (`branch_id` null) đi qua đây an
     * toàn — nó không có catalog riêng để đánh phiên bản; các menu chi nhánh
     * chép từ nó được đánh dấu ở chính lượt ghi của chúng.
     */
    private function markDirty(Menu $menu): void
    {
        $this->revisions->markDirty($menu->branch_id === null ? null : (string) $menu->branch_id);
    }
}
