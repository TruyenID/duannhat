<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1662 — hai con số Ordering nhớ đệm lên `customer_orders`: đã trả bao nhiêu, tip
 * bao nhiêu.
 *
 * Đây là **bản sao đọc nhanh**, không phải sổ cái. Sổ cái là `order_payments` và nó
 * thuộc Payments; hai cột trên đơn chỉ để khỏi phải gộp lại ở mỗi lần đọc.
 */
final readonly class OrderPaymentCacheTotals
{
    public function __construct(
        public float $totalPaid,
        public float $totalTip,
    ) {}

    public static function empty(): self
    {
        return new self(0.0, 0.0);
    }
}
