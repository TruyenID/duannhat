<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1647 — doanh thu gộp của một tập đơn, theo luật của ORDERING.
 *
 * `net` là **suy ra** (`total - tax`), không phải một cột: `customer_orders` có
 * `subtotal · discount_amount · service_charge · tax_amount · total_amount ·
 * total_tip` và KHÔNG có `net_amount`. Phép suy đó thuộc về phía sở hữu bảng —
 * để chỗ gọi tự trừ là mời mỗi chỗ gọi nghĩ ra một định nghĩa "net" riêng.
 */
final readonly class OrderRevenueRollup
{
    public function __construct(
        public float $gross,
        public float $net,
        public float $tax,
        public float $discount,
    ) {}

    public static function empty(): self
    {
        return new self(0.0, 0.0, 0.0, 0.0);
    }
}
