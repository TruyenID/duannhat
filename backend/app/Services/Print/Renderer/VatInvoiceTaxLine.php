<?php

declare(strict_types=1);

namespace App\Services\Print\Renderer;

/**
 * plan-053 T5.1d (#1909) — một dòng của khối thuế theo mức trên hoá đơn GTGT.
 *
 * Đối ứng `VatInvoiceTaxLine` bên Go (3 trường). `rate` là `float` vì **0.0 là
 * một mức hợp lệ** (非課税), không phải "chưa có dữ liệu" — cùng lý do
 * {@see PrintRenderItem::$taxRate} phân biệt `null` với `0.0`.
 *
 * Ba con số này là SNAPSHOT bất biến lấy qua `OrderTaxBreakdownReads`, không
 * phải kết quả tính lại ở tầng in ({@see ReceiptTaxSummary}).
 */
final class VatInvoiceTaxLine
{
    public function __construct(
        /** 0.0 là 非課税 — một mức thật, không phải thiếu dữ liệu. */
        public readonly float $rate = 0.0,
        public readonly int $taxable = 0,
        public readonly int $tax = 0,
    ) {}
}
