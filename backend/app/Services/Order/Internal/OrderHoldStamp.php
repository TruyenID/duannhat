<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use Illuminate\Support\Collection;

/**
 * #2063 — đóng dấu cờ ĐANG TREO lên các đơn của một đường ĐỌC.
 *
 * Một chỗ duy nhất làm việc này, vì cái dễ sai không phải phép tính (đã nằm ở
 * {@see OrderHoldStatus}) mà là **ai được đóng dấu**.
 *
 * # Chỉ đường ĐỌC
 *
 * Mọi endpoint GHI trả order về mà KHÔNG gọi hàm này, nên payload của chúng
 * không mang khoá cờ. `CustomerOrderResource` phát khoá đó **có điều kiện**:
 * vắng mặt nghĩa là *chưa đóng dấu*, không phải *không treo*.
 *
 * Gộp hai nghĩa đó là bẫy số 2 của #2063: sửa một dòng món trên đơn treo sẽ trả
 * về order không cờ, người tiêu thụ đọc thành `false`, và hai nút in hiện lại
 * trên đúng cái đơn vừa bị chặn.
 *
 * # Theo LÔ
 *
 * Nhận cả tập đơn một lần. Đường đọc trả danh sách là chỗ cờ này sống, và hỏi
 * nợ từng đơn là N+1 trên màn hình bận nhất của quán.
 */
final class OrderHoldStamp
{
    /** Khoá trên payload VÀ tên thuộc tính tạm trên model. Một tên, không hai. */
    public const ATTRIBUTE = 'is_on_hold';

    public function __construct(private readonly OrderHoldStatus $status) {}

    /**
     * Đóng dấu tại chỗ và trả lại chính tập đã nhận.
     *
     * Trả lại để dùng được ngay trong biểu thức `Resource::collection(...)` mà
     * không cần một biến trung gian — chỗ gọi càng ngắn thì càng khó quên.
     *
     * @template T of iterable<CustomerOrder>|Collection<int, CustomerOrder>
     *
     * @param  T  $orders
     * @return T
     */
    public function stamp(iterable $orders): iterable
    {
        $list = $orders instanceof Collection ? $orders->all() : $orders;
        $list = is_array($list) ? $list : iterator_to_array($list);

        if ($list === []) {
            return $orders;
        }

        $held = $this->status->forOrders($list);

        foreach ($list as $order) {
            // `false` tường minh, KHÔNG phải để trống: một đơn đã đi qua đây là
            // một đơn ĐÃ ĐƯỢC HỎI. Chỉ đơn chưa từng qua đường đọc mới được
            // mang `null`, và đó chính là điều khoá vắng mặt trên payload nói.
            $order->setAttribute(self::ATTRIBUTE, isset($held[(string) $order->id]));
        }

        return $orders;
    }

    /** Một đơn — đường đọc nào đã cầm sẵn đúng một order. */
    public function stampOne(CustomerOrder $order): CustomerOrder
    {
        $this->stamp([$order]);

        return $order;
    }
}
