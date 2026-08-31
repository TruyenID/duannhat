<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 (7b) — kết quả định giá phần chia theo món (#1225) cho MỘT khách.
 *
 * Đi qua **cùng một bộ tính** (`SplitByItemsCalculator`) mà hoá đơn tạm in ra,
 * nên hai tài liệu không thể nói khác nhau. Đó là lý do cổng không nhận lại một
 * "tổng tiền khách trả" từ Payments rồi chia lại: chia lại là bộ tính thứ hai.
 */
final readonly class InvoiceSplitPricing
{
    /** @param list<InvoiceSplitLine> $lines */
    public function __construct(
        public float $subtotal,
        public float $tax,
        public array $lines,
    ) {}
}
