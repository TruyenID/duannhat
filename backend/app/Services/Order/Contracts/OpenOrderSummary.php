<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1647 — một đơn ĐANG MỞ, ở dạng phía tiền cần để dựng danh sách "chưa trả".
 *
 * `createdAt` là ISO-8601 **chuỗi**, đã chuẩn hoá ở phía Ordering. Trả `Carbon`
 * qua ranh giới thì mỗi chỗ gọi lại tự quyết định định dạng, và đó đúng là cách
 * hai màn hình cùng dữ liệu in ra hai kiểu ngày khác nhau.
 */
final readonly class OpenOrderSummary
{
    public function __construct(
        public string $orderId,
        public ?string $orderCode,
        public string $status,
        public float $totalAmount,
        public string $createdAt,
    ) {}
}
