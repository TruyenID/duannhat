<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1647 — một đơn bị VOID, chỉ gồm hai thứ phía tiền dùng tới.
 *
 * KHÔNG mang số payment còn sống: đó là dữ liệu của Payments, và Payments tự
 * đếm trên bảng của mình. Nhét nó vào đây là bắt Ordering truy vấn
 * `order_payments` — đúng cái mà #1647 tránh.
 */
final readonly class VoidedOrderSummary
{
    public function __construct(
        public string $orderId,
        public float $totalAmount,
    ) {}
}
