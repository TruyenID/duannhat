<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/** plan-053 T5.1d (#1910) — một dòng giảm giá trên 精算. */
final class ShiftDiscountLine
{
    public function __construct(
        public readonly string $label,
        public readonly int $count,
        public readonly int $amount,
    ) {}
}
