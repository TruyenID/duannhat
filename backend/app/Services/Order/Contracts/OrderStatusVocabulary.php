<?php

namespace App\Services\Order\Contracts;

/**
 * #1596 — từ vựng trạng thái đơn mà Ordering CÔNG BỐ ra ngoài.
 *
 * Danh sách sống Ở ĐÂY, không ở model: một hằng số công bố mà lại đọc ngược vào
 * model `CustomerOrder` thì bản thân contract phụ thuộc module, và cổng
 * `PublishedContracts` (xem `config/modules.php`) từ chối đúng thứ đó. Tên model viết
 * KHÔNG kèm namespace là có chủ ý — xem `OrderPaymentLedgerReads` (#962 · 7a-7).
 * `CustomerOrder::OPEN_STATUSES` giờ trỏ về đây, nên vẫn còn đúng một nguồn.
 */
final class OrderStatusVocabulary
{
    /**
     * Các trạng thái mà đơn còn "đang sống" — khách còn dính vào nó, món còn
     * giữ chỗ trong kho. Trạng thái kết (`closed`, `voided`) không nằm đây.
     *
     * @var list<string>
     */
    public const OPEN = [
        'pending',
        'awaiting_confirmation',
        'confirmed',
        'open',
        'dining',
        'checkout',
        'paying',
    ];

    /**
     * Trạng thái đơn còn treo TIỀN ở ranh ca (#2696).
     *
     * `paying` = khách đang trả, `checkout` = đã vào màn thanh toán. Cả hai đều
     * có thể sống qua lần đóng ca mà không chặn đóng ca (`unpaid_carry`). Tập
     * này là ĐỊNH NGHĨA của Ordering — Payments không được tự liệt kê lại.
     *
     * Không phải tập con của {@see OPEN} theo nghĩa "mọi OPEN đều treo tiền":
     * `dining` / `open` là đơn đang phục vụ trong ca hiện tại, không phải đơn
     * kẹt qua ranh.
     *
     * @var list<string>
     */
    public const UNRESOLVED_MONEY = [
        'checkout',
        'paying',
    ];
}
