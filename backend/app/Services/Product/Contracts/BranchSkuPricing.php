<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #1597 — cổng TRA GIÁ BÁN mà Catalog công bố cho Ordering.
 *
 * #1597 nói 54 cạnh Ordering → Catalog cần Catalog *"công bố kiểu kết quả (giá +
 * thuộc tính món tại thời điểm bán)"*. Đây là mảnh đầu tiên của kiểu đó, cắt
 * theo chỗ gọi hẹp nhất chứ không thiết kế trước cả bộ.
 *
 * ⚠️ **KHÔNG phải bản đồ giá bất biến thứ hai.** `catalog_revisions` (#1092) đã
 * là ảnh chụp giá BẤT BIẾN cho đường offline có chữ ký, và nó phải giữ nguyên
 * vai trò đó — cổng này đọc giá **hiện tại** cho đường online, không lưu, không
 * đánh version, không được dùng để định giá lại một đơn đã ký. Hai thứ trông
 * giống nhau nhưng trả lời hai câu khác nhau: *"bây giờ bán bao nhiêu"* và
 * *"lúc đó đã bán bao nhiêu"*.
 */
interface BranchSkuPricing
{
    /**
     * Giá bán của một SKU theo menu của MỘT chi nhánh.
     *
     * `null` nghĩa là SKU không tồn tại — chỗ gọi phân biệt được với "tồn tại
     * nhưng không được bán" ({@see SellableSkuPrice::$isSellable}), vì hai ca đó
     * là hai thông điệp lỗi khác nhau cho người dùng.
     */
    public function forBranch(string $branchId, string $productSkuId): ?SellableSkuPrice;
}
