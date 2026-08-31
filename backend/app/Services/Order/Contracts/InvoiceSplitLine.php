<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 (7b) — một dòng của hoá đơn chia theo món (#1225 `by_items`): số ĐƠN VỊ
 * khách này trả và phần tiền tương ứng, do bộ tính chia bill của Ordering ra.
 *
 * `subtotal` là số của bộ tính, KHÔNG phải `unit_price × units`: chia bill có
 * phân bổ giảm giá và phụ thu theo phần, và nhân lại tay sẽ lệch với hoá đơn in.
 */
final readonly class InvoiceSplitLine
{
    public function __construct(
        public string $itemId,
        public int $units,
        public float $subtotal,
    ) {}
}
