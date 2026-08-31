<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1909) — một dòng hàng trên hoá đơn GTGT.
 *
 * Đối ứng `VatInvoiceLine` bên Go (7 trường). Khác {@see PrintRenderItem} — dòng
 * món của phiếu bán hàng — ở chỗ dòng hoá đơn mang `lineTotal` ĐÃ CHỐT thay vì
 * để tầng in nhân lại: hoá đơn là chứng từ đã phát hành, và một phép nhân lặp
 * lại là một chỗ cho hai con số khác nhau trên cùng tờ giấy.
 */
final class VatInvoiceLine
{
    /** @param list<VatInvoiceTopping> $toppings */
    public function __construct(
        public readonly string $name = '',
        public readonly int $quantity = 0,
        public readonly int $unitPrice = 0,
        /** ĐÃ CHỐT lúc phát hành — đừng nhân lại từ qty × đơn giá. */
        public readonly int $lineTotal = 0,
        public readonly string $variantName = '',
        public readonly string $note = '',
        public readonly array $toppings = [],
    ) {}
}
