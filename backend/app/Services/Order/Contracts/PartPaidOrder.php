<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1992 — một đơn khách TRẢ CHƯA ĐỦ rồi đi, ở dạng màn hình tra cứu cần.
 *
 * `unpaidAmount` được tính sẵn chứ không để người gọi tự trừ: đó là con số quán
 * đang bị thiếu, và hai chỗ tự trừ là hai chỗ có thể trừ khác nhau.
 *
 * `openedAt` là chuỗi thời gian **y như đã lưu** (`Y-m-d H:i:s`), cùng lý do với
 * `OpenAccountDebt` bên Payments: pos-web in thẳng mốc thời gian ra màn hình để
 * thu ngân phân biệt bàn đang phục vụ với bàn đã bỏ đi, nên đổi định dạng ở đây
 * là đổi giao diện.
 */
final readonly class PartPaidOrder
{
    public function __construct(
        public string $orderId,
        public ?string $orderCode,
        public string $customerId,
        public float $totalAmount,
        public float $paidAmount,
        public float $unpaidAmount,
        public ?string $openedAt,
    ) {}
}
