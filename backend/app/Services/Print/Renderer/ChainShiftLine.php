<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1910) — một ca trong BÁO CÁO CHUỖI (kết ca cuối, plan-046).
 *
 * Mỗi dòng là bản rút gọn của `settlement_snapshot` BẤT BIẾN của một ca. Tổng
 * của chuỗi là Σ các snapshot này — KHÔNG phải tính lại từ đơn hàng. Tính lại
 * sẽ ra số khác khi có refund cross-period (plan-046 R7).
 */
final class ChainShiftLine
{
    public function __construct(
        public readonly int $sequence,
        /** `handover` | `final` — ca bàn giao hay ca đóng chuỗi. */
        public readonly string $kind,
        public readonly string $operator = '',
        public readonly string $openedAt = '',
        public readonly string $closedAt = '',
        public readonly int $gross = 0,
        public readonly int $net = 0,
        public readonly int $tax = 0,
        public readonly int $countedCash = 0,
        public readonly int $expectedCash = 0,
        public readonly int $variance = 0,
        public readonly int $checkCount = 0,
        public readonly int $discount = 0,
    ) {}
}
