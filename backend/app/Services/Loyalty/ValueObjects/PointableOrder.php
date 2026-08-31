<?php

declare(strict_types=1);

namespace App\Services\Loyalty\ValueObjects;

/**
 * #1596 — đúng những trường của một đơn mà việc TÍNH ĐIỂM cần, và không hơn.
 *
 * `CustomerPointService` nhận `App\Models\CustomerOrder` ở ba method public,
 * nhưng đo thân method thì nó chỉ đọc **chín trường vô hướng**. Đây là ca **thu
 * hẹp thuần** (#1612): chỗ gọi duy nhất trong production là listener
 * `AwardPointsOnOrderPaid`, và listener **đã cầm sẵn** model từ `OrderPaid` —
 * nên đổi chữ ký KHÔNG thêm một truy vấn nào.
 *
 * Khác với ca `CustomerPointService` từng bị đánh giá là "phải đổi event
 * trước": điều đó chỉ đúng nếu service cần **tra cứu** thêm. Nó không cần.
 * Listener là Composition, được phép cầm model và bóc trường ra.
 *
 * Đây là value object của **Loyalty**, không phải cổng công bố của Ordering:
 * Loyalty tự khai nó cần gì, nên Ordering không phải mở thêm bề mặt cho một
 * người dùng.
 */
final readonly class PointableOrder
{
    public function __construct(
        public string $orderId,
        public ?string $orderCode,
        public string $organizationId,
        public ?string $customerId,
        public ?string $branchId,
        /**
         * #1674 — brand nào SỞ HỮU đơn này, để tra tỉ lệ tích điểm mà HQ tự
         * cấu hình. Đây là `customer_orders.brand_id` (khoá cục bộ), không
         * phải `console_brand_id` của SSO.
         */
        public ?string $brandId,
        public float $totalAmount,
        public float $subtotal,
        public float $discountAmount,
    ) {}
}
