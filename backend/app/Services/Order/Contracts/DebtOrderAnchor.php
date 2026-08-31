<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1993 — một ĐƠN mà một khoản nợ đang bám vào, ở dạng phía tiền cần.
 *
 * `customerId` có thể `null` (đơn khách vãng lai). Nợ ghi trên một đơn như vậy
 * là không thể xảy ra — `OrderPaymentStoreRequest` chặn `on_account` khi đơn
 * không có khách (`customer_required_for_debt`) — nhưng cổng này công bố sự thật
 * về CỘT chứ không công bố luật của phía tiền, nên nó vẫn cho phép `null` và để
 * người gọi tự quyết định. Trả về kiểu không-null ở đây là mang một bất biến của
 * Payments đặt vào từ vựng của Ordering.
 */
final readonly class DebtOrderAnchor
{
    public function __construct(
        public string $orderId,
        public ?string $customerId,
        public ?string $orderCode,
    ) {}
}
