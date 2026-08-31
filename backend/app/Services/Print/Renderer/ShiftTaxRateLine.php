<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1910) — một dòng thuế theo mức trên 精算.
 *
 * KHÁC {@see ReceiptTaxBlock}: khối kia thuộc MỘT phiếu và đến từ snapshot của
 * đơn; dòng này thuộc MỘT CA và đến từ `settlement_snapshot` bất biến của
 * plan-046. Đừng dùng lẫn — hai nguồn, hai vòng đời.
 */
final class ShiftTaxRateLine
{
    public function __construct(
        public readonly float $rate,
        public readonly int $taxableSales,
        public readonly int $tax,
    ) {}
}
