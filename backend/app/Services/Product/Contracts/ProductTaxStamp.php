<?php

declare(strict_types=1);

namespace App\Services\Product\Contracts;

/**
 * #2346 — cổng để module NGOÀI Product đóng dấu loại thuế mặc định lên các
 * product của một brand mà chưa ai gán gì.
 *
 * Tồn tại vì `products` thuộc aggregate `product`: một service ở module khác
 * (`Provisioning`) ghi thẳng bảng đó là vi phạm ranh giới plan-047 gate 4, và
 * `architecture:domain-writers` bắt đúng chỗ đó (dev đỏ 4 bài).
 *
 * Hẹp có chủ đích: chỉ hàng CHƯA GẮN GÌ. Không có method nào đổi loại thuế đã
 * gán — đó là quyết định của người vận hành, baseline không được đụng.
 */
interface ProductTaxStamp
{
    /**
     * Gán `$taxTypeId` cho mọi product của brand đang để trống loại thuế.
     *
     * @return int số hàng đã đóng dấu
     */
    public function stampMissing(string $brandId, string $taxTypeId): int;

    /** Đếm product của brand còn để trống loại thuế. */
    public function countMissing(string $brandId): int;
}
