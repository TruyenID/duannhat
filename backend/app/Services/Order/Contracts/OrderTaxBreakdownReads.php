<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 (7b) — tổng hợp thuế theo TỪNG MỨC của một lô đơn, do Ordering công bố.
 *
 * Hai chỗ gọi đều thuộc **Payments** và đều là bề mặt kết ca:
 * `ShopTillTrackingService` (Z-report) và `TillSessionService`
 * (`settlement_snapshot` bất biến của plan-046). Trước bản vá cả hai `new
 * OrderTaxBreakdownAggregator` thẳng — Payments dựng một service của Ordering.
 *
 * ## Vì sao cổng chỉ CHUYỂN TIẾP, không được tính lại
 *
 * Con số ở đây là **snapshot bất biến trên từng dòng đơn**, không phải phép tính
 * thuế mới: `taxable = Σ item.subtotal`, `tax = Σ item.tax_amount`, gom theo
 * `item.tax_rate` đã đóng băng lúc thanh toán, bỏ dòng đã void. Làm tròn đã xảy
 * ra **một lần cho mỗi nhóm mức thuế** (インボイス, 端数処理は税率ごとに1回) rồi mới
 * phân bổ xuống dòng, nên cộng lại các dòng cho ra đúng số nhóm. Cộng theo bất
 * kỳ cách nào khác — làm tròn lại từng dòng, hay tính `tax = taxable × rate` —
 * sẽ ra một con số KHÁC với hoá đơn đã in, và đó là báo cáo thuế.
 *
 * Vì vậy hiện thực phải trỏ vào `OrderTaxBreakdownAggregator::forOrders()`, y
 * như {@see OrderPaymentLedgerReads::netCollectedForOrder()} phải trỏ vào
 * `OrderPayment::netCollectedForOrder()` (#816).
 */
interface OrderTaxBreakdownReads
{
    /**
     * Gom các dòng CHƯA void của những đơn này thành các hàng theo mức thuế, kèm
     * tổng 税抜 (`net`) / 消費税 (`tax`) / 税込 (`gross`).
     *
     * `net` là doanh thu chịu thuế (Σ subtotal); `gross = net + tax`. Cả hai được
     * phát ra tường minh vì hai quy ước doanh thu khác nhau (summary cộng gross,
     * theo-sản-phẩm cộng net) phải đối chiếu được trên cùng một payload.
     *
     * Lô rỗng ⇒ mọi số 0 và `by_rate` rỗng, không chạm DB.
     *
     * @param  iterable<int|string>  $orderIds
     * @return array{
     *   net: float,
     *   tax: float,
     *   gross: float,
     *   by_rate: list<array{rate: float, taxable: float, tax: float}>,
     * }
     */
    public function forOrders(iterable $orderIds): array;
}
