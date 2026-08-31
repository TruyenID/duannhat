<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1909) — topping in dưới một dòng hoá đơn GTGT.
 *
 * Đối ứng `VatInvoiceTopping` bên Go (3 trường). Hẹp hơn
 * {@see PrintRenderTopping} một cách CÓ CHỦ ĐÍCH: nó không mang `modifierType`,
 * vì hoá đơn đã phát hành in mọi modifier như một dòng tiền, không rẽ nhánh
 * giữa "topping tính tiền" và "ghi chú chế biến".
 */
final class VatInvoiceTopping
{
    public function __construct(
        public readonly string $name = '',
        public readonly int $quantity = 0,
        public readonly int $unitPrice = 0,
    ) {}
}
