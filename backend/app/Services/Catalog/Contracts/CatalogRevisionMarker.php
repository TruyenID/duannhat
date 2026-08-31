<?php

declare(strict_types=1);

namespace App\Services\Catalog\Contracts;

/**
 * #1661 — Catalog công bố quyền nói *"bản catalog của chỗ này đã cũ"*.
 *
 * ## Vì sao cần công bố thay vì để Catalog tự observe
 *
 * Bản catalog phải tiến khi **bất kỳ tầng thuế nào** của #1218 đổi, và hai tầng
 * cuối nằm ở module khác: tầng 5 là `shop_order_settings.default_tax_type_id`
 * (Ordering), tầng 6 + mọi **thuế suất** là `tax_types` (Pricing).
 *
 * Bản đầu của #1661 cho `CatalogRevisionObserver` tự nhận hai model đó. Deptrac
 * đỏ ngay, và nó đỏ ĐÚNG: Catalog đi đọc bảng của Ordering và Pricing. Đảo
 * chiều — mỗi module tự observe model của MÌNH rồi báo qua cổng này — là chiều
 * đúng: chủ sở hữu dữ liệu là người biết khi nào nó đổi.
 *
 * `Observers\<Model>Observer` được quy sở hữu **theo tên model**, nên
 * `App\Observers\ShopOrderSettingObserver` tự thuộc Ordering và
 * `App\Observers\TaxTypeObserver` tự thuộc Pricing — không cần khai gì thêm.
 *
 * ## Vì sao là cổng chứ không phải sự kiện
 *
 * Một `CatalogStaleEvent` nghe lỏng hơn, nhưng `markDirty` phải chạy TRONG
 * transaction đang mở: `CatalogRevisionService` đăng ký `DB::afterCommit` và gom
 * mọi đánh dấu của một transaction thành **một** lượt dựng lại. Bắn sự kiện rồi
 * xử lý ở listener queue là đánh mất đúng tính chất đó.
 *
 * ## Không ném, không trả gì
 *
 * Chỗ gọi đang ở giữa một lượt lưu cấu hình. Đánh dấu hỏng không được làm hỏng
 * việc lưu; và `null` là no-op, không phải lỗi (bản ghi chưa gắn chi nhánh/
 * thương hiệu thì chưa có catalog nào để đánh phiên bản).
 */
interface CatalogRevisionMarker
{
    /** Bản catalog của MỘT chi nhánh đã cũ. */
    public function markDirty(?string $branchId): void;

    /** Bản catalog của MỌI chi nhánh thuộc một thương hiệu đã cũ. */
    public function markBrandDirty(?string $brandId): void;
}
