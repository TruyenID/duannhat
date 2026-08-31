<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #2696 — một đơn còn TREO TIỀN, ở dạng phía tiền cần để dựng danh sách lúc mở ca.
 *
 * Anh em của {@see OpenOrderSummary} và cố ý tách khỏi nó: "đang mở" là vòng đời
 * bình thường của đơn, còn "treo tiền qua ranh ca" là một bất thường — gộp hai
 * khái niệm vào một DTO sẽ khiến người đọc tưởng mọi đơn đang mở đều đáng báo.
 *
 * `tableReleased` là thứ phía tiền KHÔNG tự suy được: bàn đã nhả nghĩa là đơn
 * mồ côi, không màn hình nào khác cho thấy nó. Đó là ca ORD-2026-0191 trên
 * production — kẹt `checkout` 17 giờ với ¥700 mà không ai thấy.
 *
 * `createdAt` là ISO-8601 **chuỗi**, chuẩn hoá ở phía Ordering — cùng lý do với
 * `OpenOrderSummary`: trả `Carbon` qua ranh giới thì mỗi chỗ gọi tự chọn định
 * dạng, và đó là cách hai màn hình cùng dữ liệu in ra hai kiểu ngày.
 */
final readonly class UnresolvedOrderSummary
{
    public function __construct(
        public string $orderId,
        public ?string $orderCode,
        public string $status,
        public float $totalAmount,
        public bool $tableReleased,
        public string $createdAt,
    ) {}
}
