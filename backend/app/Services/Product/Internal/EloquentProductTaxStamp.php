<?php

declare(strict_types=1);

namespace App\Services\Product\Internal;

use App\Models\Product;
use App\Services\Product\Contracts\ProductPersistencePort;
use App\Services\Product\Contracts\ProductTaxStamp;

/**
 * #2346 — đường ghi DUY NHẤT cho việc đóng dấu loại thuế mặc định lên product
 * CHƯA GẮN GÌ. Sống trong module Product vì `products` là bảng của aggregate
 * `product`; `config/domain-mutation-guard.php` khai file này trong
 * `boundaries` của aggregate đó.
 *
 * Vì sao là một class riêng thay vì thêm method thứ 38 vào
 * {@see ProductPersistencePort}: cổng đó nhận
 * 9 dependency của cả đường ghi catalog và mọi method của nó nhận một command
 * object. Baseline provisioning chạy ở đường đăng nhập SSO — tiêm cả cỗ máy đó
 * vào để cập nhật một cột là đổi một cạnh ranh giới lấy chín cạnh khởi tạo.
 * Cùng lập luận đã dùng cho {@see EloquentReviewedSkuDirectory} (#962).
 *
 * Vì sao KHÔNG dùng lại khuôn `App\Console\Maintenance\TaxExemptBrandPersistence`:
 * class đó tự khai là maintenance-only và "runtime services must never reuse
 * maintenance-only write access" (plan-047 T4.14). Baseline provisioner CHÍNH
 * LÀ đường runtime — Platform sync, tạo chi nhánh, seeder, `provisioning:reconcile`.
 */
final class EloquentProductTaxStamp implements ProductTaxStamp
{
    /**
     * `whereNull('tax_type_id')` là bất biến của hàm này, KHÔNG phải tối ưu.
     *
     * Bỏ nó ra là dán đè lựa chọn 軽減税率 (8%) của người vận hành thành mức
     * chuẩn (10%) ở mọi lượt baseline — đúng lỗi thu vượt mà #2320 vừa sửa
     * (13 hàng phở/bún/chả giò của Betoya, mỗi lượt seed lại quay về 10%).
     * Chiều sai là thu vượt của khách, không phải thu thiếu.
     */
    public function stampMissing(string $brandId, string $taxTypeId): int
    {
        return Product::query()
            ->where('brand_id', $brandId)
            ->whereNull('tax_type_id')
            ->update(['tax_type_id' => $taxTypeId]);
    }

    public function countMissing(string $brandId): int
    {
        return Product::query()
            ->where('brand_id', $brandId)
            ->whereNull('tax_type_id')
            ->count();
    }
}
