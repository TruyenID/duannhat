<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d slice 1a (#1908) — một dòng khối thuế in ra:
 *
 *     8%対象  ¥1,000 (内消費税 ¥80)
 *
 * Đối ứng của `receiptTaxBlock` (workstation `internal/service/print_tax_blocks.go`).
 *
 * `taxable` và `tax` là **số nguyên đơn vị tiền nhỏ nhất đã chốt**, không phải
 * thứ để tính tiếp: chúng đến từ snapshot bất biến trên dòng đơn, đã làm tròn
 * một lần cho mỗi nhóm mức thuế. Nhân/chia lại chúng ở tầng in là cách tạo ra
 * một con số khác với hoá đơn đã phát hành — xem {@see ReceiptTaxSummary}.
 */
final class ReceiptTaxBlock
{
    public function __construct(
        public readonly float $rate,
        /** 課税対象 — cơ sở chịu thuế in sau "{rate}%対象". */
        public readonly int $taxable,
        /** 消費税 — số thuế in trong "(内消費税 …)". */
        public readonly int $tax,
    ) {}
}
