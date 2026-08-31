<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use DateTimeInterface;

/**
 * #1622 — cổng Payments công bố: **tiền đã thu, đã ròng, trong một khoảng của
 * một chi nhánh**.
 *
 * Báo cáo doanh thu POS trước đây tự cộng `order_payments` — sổ phụ AR của
 * Payments (#1151) — bằng query builder thô. Deptrac không thấy (không import
 * class nào); chỉ `architecture:raw-table-reads` đếm được.
 *
 * ## Cả hai method đều đã RÒNG, và đó là hợp đồng chứ không phải chi tiết
 *
 * "Ròng" ở đây là **định nghĩa tiền** của repo này, không phải một tuỳ chọn:
 *
 * - hoàn tiền được ghi thành dòng `amount` ÂM, nên **cộng** chúng vào là trừ đi
 *   phần đã trả lại;
 * - **#1123** — chargeback (rút tiền) là một dòng contra như hoàn tiền; THẮNG
 *   tranh chấp lại ghi thêm một dòng DƯƠNG `dispute_kind=reinstatement` không
 *   có `refund_of_id`. Bỏ sót nhánh sau thì một vụ thắng vẫn bị trừ mãi — KPI
 *   báo 0 trong khi bảng theo phương thức nói tiền đã về;
 * - dòng `settles_payment_id` bị loại: đó là bút toán tất toán của thanh toán
 *   bất đồng bộ (#1125), cộng vào là **đếm hai lần**.
 *
 * Một cổng trả số THÔ rồi để chỗ gọi tự trừ sẽ mời mỗi người đọc tự định nghĩa
 * lại "doanh thu" — và ba luật trên thì không ai nhớ hết.
 */
interface BranchPaymentTotals
{
    /**
     * Tổng các dòng ĐẢO TIỀN trong khoảng — trả về số **âm hoặc 0**.
     *
     * Cộng thẳng vào doanh thu gộp để ra doanh thu ròng; không đổi dấu ở chỗ
     * gọi (đổi dấu hai lần là lỗi đã xảy ra ở đúng chỗ này).
     */
    public function reversalTotal(
        string $branchId,
        string $organizationId,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): float;

    /**
     * Tiền RÒNG theo từng phương thức thanh toán, đã sắp giảm dần theo số tiền.
     *
     * @return list<array{method_id: ?string, code: ?string, name: ?string, amount: int}>
     */
    public function netByPaymentMethod(
        string $branchId,
        string $organizationId,
        DateTimeInterface $from,
        DateTimeInterface $to,
    ): array;
}
