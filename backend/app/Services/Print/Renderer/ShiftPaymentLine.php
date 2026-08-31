<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/** plan-053 T5.1d (#1910) — một dòng "theo phương thức thanh toán" trên 精算. */
final class ShiftPaymentLine
{
    public function __construct(
        /** Khoá tender (`cash`, `qr`…). Đây là TỪ VỰNG TIỀN — xem #1881. */
        public readonly string $code,
        public readonly string $label,
        public readonly int $count,
        public readonly int $amount,
    ) {}
}
