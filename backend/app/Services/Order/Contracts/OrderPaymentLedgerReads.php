<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1662 (#962 · 7a-6) — Ordering đọc sổ tiền của một đơn, qua cổng thay vì với thẳng
 * sang model `OrderPayment` của Payments.
 *
 * Tên model cố ý viết KHÔNG kèm namespace: `DomainMutationContractsTest` quét cả file
 * chứ không riêng phần `use`, nên một FQCN trong văn xuôi cũng làm đỏ (#962 · 7a-7).
 *
 * Do **Ordering** khai vì lý do publish-theo-namespace — xem
 * {@see OpenTillSessionLookup}. Payments hiện thực.
 *
 * ## Hai phép đọc này KHÁC NHAU, và đừng gộp
 *
 * | | dùng ở đâu | nghĩa |
 * |---|---|---|
 * | `cachedTotalsForOrder` | làm mới cột đệm trên đơn | **tổng đã thu** + tip |
 * | `netCollectedForOrder` | chặn void đơn còn tiền | **tiền đơn CÒN GIỮ** sau hoàn |
 *
 * Cái thứ hai không suy ra được từ cái thứ nhất. Một đơn đã hoàn tiền vẫn có
 * `totalPaid > 0` (dòng gốc giữ nguyên +X, chỉ đổi trạng thái sang `refunded`) trong
 * khi net = 0.
 *
 * ## Cạm bẫy tiền — #816, đã trả giá thật
 *
 * `netCollectedForOrder` PHẢI chuyển tiếp thẳng
 * `OrderPayment::netCollectedForOrder()`. **Không** được tự gộp lại ở đây. Bản cũ từng
 * chỉ cộng dòng `succeeded`, mà một lần hoàn tiền được ghi sổ là: dòng gốc +X giữ
 * nguyên và chuyển `refunded`, cộng thêm một dòng -X `succeeded`. Cộng riêng
 * `succeeded` thì mất +X mà vẫn giữ -X ⇒ đẻ ra tín dụng ma huỷ mất tiền mặt thật của
 * người khác trên hoá đơn chia đôi. Có đúng MỘT định nghĩa "tiền đơn còn giữ"; cổng
 * này chỉ được trỏ vào nó.
 */
interface OrderPaymentLedgerReads
{
    /**
     * Tổng đã thu + tip để đắp vào cột đệm của đơn.
     *
     * Tập dòng phải giữ nguyên như đường cũ: bỏ dòng settlement
     * (`metadata->settles_payment_id` khác null), gộp **cả** `succeeded` lẫn
     * `refunded`.
     */
    public function cachedTotalsForOrder(string $orderId): OrderPaymentCacheTotals;

    /**
     * Tiền đơn CÒN GIỮ sau khi trừ hoàn. `> 0` nghĩa là không được void.
     */
    public function netCollectedForOrder(string $orderId): float;
}
