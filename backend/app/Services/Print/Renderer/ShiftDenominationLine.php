<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1910) — một dòng đếm mệnh giá (phiếu mở ca và phiếu 精算).
 *
 * `subtotal` được CHỞ THEO chứ không tính lại từ `value × quantity`: nó là con
 * số thu ngân đã đếm và đã ký, và một phép nhân lại ở tầng in sẽ che mất lệch
 * nếu có.
 */
final class ShiftDenominationLine
{
    public function __construct(
        public readonly int $value,
        public readonly int $quantity,
        public readonly int $subtotal,
    ) {}
}
