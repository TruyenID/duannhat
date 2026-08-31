<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1596 — Ordering công bố: **khách này còn đơn nào chưa đóng không?**
 *
 * `CustomerService::delete()` hỏi câu đó để chặn xoá khách còn đơn dở (plan-042),
 * và trước bản vá nó tự `CustomerOrder::open()->where('customer_id', …)` — tức
 * CustomerEngagement tự đọc model của Ordering.
 *
 * Cùng một hình dạng với {@see OpenOrderSkuUsage} (#1622): trả `bool`, không trả
 * danh sách đơn. Chỗ gọi chỉ cần chặn-hay-cho-qua; trả danh sách là mời người
 * sau đọc chi tiết đơn hàng từ CustomerEngagement.
 *
 * **Không nhận `organizationId`** — giữ đúng hành vi bản cũ, vốn lọc theo mỗi
 * `customer_id`. Một khách đã thuộc một tổ chức, nên id khách đã hàm ý phạm vi;
 * thêm tham số ở đây sẽ là siết chặt âm thầm trong một PR dời ranh giới.
 */
interface CustomerOrderPresence
{
    /** Trạng thái "chưa đóng" là {@see OrderStatusVocabulary::OPEN}. */
    public function hasOpenOrder(string $customerId): bool;
}
