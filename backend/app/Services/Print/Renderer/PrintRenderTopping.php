<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1925) — một dòng topping in dưới dòng món.
 *
 * Đối ứng của `ItemTopping` ở phần mà `printToppingLines`
 * (workstation `internal/service/print_service.go`) thật sự đọc — bốn trường,
 * không phải cả struct.
 */
final class PrintRenderTopping
{
    public function __construct(
        public readonly string $name,
        public readonly int $quantity,
        public readonly int $unitPrice,
        /**
         * Phân loại của modifier. `printToppingLines` rẽ theo nó để quyết định
         * dòng in ra là một topping tính tiền hay một ghi chú chế biến, nên nó
         * KHÔNG phải metadata trang trí.
         */
        public readonly string $modifierType = '',
    ) {}
}
