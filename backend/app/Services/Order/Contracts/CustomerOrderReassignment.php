<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1550 — Ordering công bố: **chuyển lịch sử đơn của khách A sang khách B.**
 *
 * Gộp khách là thao tác của CustomerEngagement, nhưng `customer_orders` là bảng
 * của Ordering (`Order\Internal\EloquentOrderPersistence`).
 * Bản dựng đầu của `mergeCustomers` ghi thẳng `DB::table($table)->update(...)`
 * với tên bảng lấy từ mảng — và `architecture:domain-writers` bắt được, đúng
 * việc của nó: đó là một cửa ghi vào aggregate khác, lại còn ở dạng rào không
 * đọc nổi tên bảng (`dynamic-table`), tức không kiểm toán được.
 *
 * Cùng hình dạng với {@see CustomerOrderPresence} (#1596): CustomerEngagement
 * hỏi/ra lệnh qua một cổng hẹp, không chạm model của Ordering.
 *
 * **Trả về số dòng đã chuyển**, vì bên gọi báo cáo con số đó cho người vận hành
 * sau khi gộp — không trả danh sách đơn, để CustomerEngagement không có đường
 * đọc chi tiết đơn hàng qua cửa này.
 */
interface CustomerOrderReassignment
{
    /**
     * Trỏ mọi đơn của `$sourceCustomerId` sang `$targetCustomerId`.
     *
     * KHÔNG lọc theo trạng thái: gộp phải kéo theo cả lịch sử đã đóng sổ, nếu
     * không thì hồ sơ còn lại thiếu mất phần đời trước khi gộp.
     */
    public function reassignCustomer(string $sourceCustomerId, string $targetCustomerId): int;
}
