<?php

namespace App\Listeners\Loyalty;

use App\Events\OrderPaid;
use App\Services\Loyalty\CustomerPointService;
use App\Services\Loyalty\ValueObjects\PointableOrder;
use Throwable;

/**
 * #1441 — tích điểm khi một đơn trả xong tiền.
 *
 * Vì sao móc vào `OrderPaid` chứ không vào từng cổng thanh toán: `OrderPaid`
 * bắn từ `OrderClosingService::close`, tức là CHỖ DUY NHẤT một đơn chuyển
 * sang closed, bất kể tiền vào qua Stripe / PayPay / tiền mặt ở quầy / đồng
 * bộ từ workstation. Móc vào từng cổng thì mỗi cổng mới thêm sau này lại là
 * một lần quên tích điểm.
 *
 * KHÔNG được để hỏng đường tiền. Đơn đã đóng, tiền đã ghi — nếu bút toán điểm
 * ngã thì khách phải vẫn thấy "thanh toán thành công", còn lỗi thì đi vào log
 * cho người xử lý. Đây cùng lý lẽ với `ShouldRescue` trên chính event này
 * (#1208): lần đầu bật Reverb đừng là lần đầu khách trả tiền xong ăn HTTP 500.
 */
class AwardPointsOnOrderPaid
{
    public function __construct(private CustomerPointService $points) {}

    public function handle(OrderPaid $event): void
    {
        try {
            // #1596 — listener là Composition nên nó cầm model và BÓC TRƯỜNG ra;
            // Loyalty chỉ nhận đúng chín giá trị nó dùng. Không thêm truy vấn nào:
            // `OrderPaid` đã mang sẵn đơn.
            $this->points->earnForOrder(new PointableOrder(
                orderId: (string) $event->order->id,
                orderCode: $event->order->order_code === null ? null : (string) $event->order->order_code,
                organizationId: (string) $event->order->organization_id,
                customerId: $event->order->customer_id === null ? null : (string) $event->order->customer_id,
                branchId: $event->order->branch_id === null ? null : (string) $event->order->branch_id,
                brandId: $event->order->brand_id === null ? null : (string) $event->order->brand_id,
                totalAmount: (float) $event->order->total_amount,
                subtotal: (float) $event->order->subtotal,
                discountAmount: (float) $event->order->discount_amount,
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
