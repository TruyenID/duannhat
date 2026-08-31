<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 (7b) — một topping đã chọn trên một dòng đơn, đã giải ngôn ngữ.
 *
 * Plan-013 Phase 2: hoá đơn phải liệt kê topping, nếu không một dòng có phụ thu
 * topping sẽ in ra `unit_price × qty ≠ line_total` mà không giải thích được.
 *
 * `unitPrice` là **chuỗi**, không phải float — nó được ghi thẳng vào
 * `items_json` của hoá đơn, và hoá đơn là tài liệu pháp lý bất biến. Ép qua
 * float rồi in lại có thể đổi cách hiển thị số đã đóng băng.
 */
final readonly class InvoiceOrderLineTopping
{
    public function __construct(
        public ?string $toppingGroupItemId,
        public ?string $name,
        public int $quantity,
        public string $unitPrice,
    ) {}
}
