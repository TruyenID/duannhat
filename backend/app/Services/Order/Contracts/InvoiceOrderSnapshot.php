<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 (7b) — mọi thứ Payments cần đọc từ ĐƠN để phát một 適格請求書 / hoá đơn
 * GTGT, chốt tại một thời điểm.
 *
 * ## Vì sao MỘT vật thể gộp, không phải một chuỗi getter
 *
 * `OrderSnapshot` (#1544) cố tình hẹp — tám vô hướng, "no line items". Đường xuất
 * hoá đơn thì ngược lại: nó là chỗ DUY NHẤT trong hệ đọc đơn ở mức chi tiết nhất
 * (mọi dòng, mọi topping, tên đã giải ngôn ngữ, phân rã thuế theo mức). Cắt nó
 * thành mười cổng hẹp sẽ ra mười lượt truy vấn cho một tài liệu, và tệ hơn: mười
 * ẢNH CHỤP ở mười thời điểm khác nhau. Hoá đơn phải nhất quán nội tại — Σ dòng,
 * `subtotal`, `tax_breakdown` và `total` cùng nói về một trạng thái đơn duy nhất
 * — nên chúng phải rời Ordering CÙNG NHAU.
 *
 * Đây cũng là lý do cổng không phát ra model: một `CustomerOrder` truyền sang
 * Payments là lời mời lazy-load trường thứ mười một sáu tháng sau, ở một thời
 * điểm khác.
 *
 * ## Bất biến tiền — đừng "dọn"
 *
 * - `taxBreakdown` là phân rã **theo từng mức thuế** (インボイス: 端数処理は税率
 *   ごとに1回). Đừng gộp thành một mức; một đơn hợp lệ trải trên hai mức và một
 *   dòng không nói được điều đó.
 * - `itemsSubtotal` = Σ `subtotal` của các dòng CHƯA VOID. `voidItem()` chỉ lật
 *   trạng thái và GIỮ NGUYÊN `subtotal`, nên bỏ bộ lọc void đi là in ra một tài
 *   liệu thuế khai khống (#821 A2).
 * - `discountAmount` / `serviceCharge` / `taxAmount` / `totalAmount` là số ĐÃ
 *   ĐÓNG BĂNG lúc thanh toán, không phải số tính lại. Hoá đơn pháp lý phải khớp
 *   cái khách đã trả.
 * - `currencyCode` đến từ `shop_order_settings` theo CHI NHÁNH, **không bao giờ**
 *   từ đơn (#1651 — `customer_orders` không có cột tiền tệ; đọc sai nguồn từng
 *   ghi nhận 1.99 USD doanh thu ma, #821 E3).
 */
final readonly class InvoiceOrderSnapshot
{
    /**
     * @param  list<array{rate: float, taxable: float, tax: float}>  $taxBreakdown
     * @param  list<InvoiceOrderLine>  $lines
     */
    public function __construct(
        public string $branchId,
        public ?string $customerId,
        /** Ngôn ngữ in đã giải (shop ?? brand ?? branch ?? 'ja') — #1152/#1446. */
        public string $displayLocale,
        /** `null` = chi nhánh chưa khai tiền tệ; người gọi tự chọn dự phòng. */
        public ?string $currencyCode,
        public float $itemsSubtotal,
        public float $discountAmount,
        public float $serviceCharge,
        public float $taxAmount,
        public float $totalAmount,
        public array $taxBreakdown,
        public array $lines,
    ) {}

    /** @return array<string, InvoiceOrderLine> */
    public function linesById(): array
    {
        $out = [];
        foreach ($this->lines as $line) {
            $out[$line->itemId] = $line;
        }

        return $out;
    }
}
