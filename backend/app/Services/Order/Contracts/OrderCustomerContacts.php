<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #962 — ĐƯỜNG LIÊN LẠC gắn với một đơn, do Ordering công bố.
 *
 * Thu hồi (recall) của Inventory phải trả lời hai câu về những đơn đã tiêu thụ
 * lô hàng bị thu hồi, và cả hai đều là câu hỏi về bảng `customer_orders`:
 *
 *     RecallService      — ai là KHÁCH ĐÃ ĐĂNG KÝ của từng đơn (để gửi thông báo)
 *     RecallDrillService — bao nhiêu đơn CÓ THỂ liên lạc được (KPI diễn tập)
 *
 * Hai chỗ ấy đang tự `CustomerOrder::query()`, tức Inventory đọc thẳng bảng của
 * Ordering. Cổng này hẹp đúng bằng hai câu hỏi đó — nó KHÔNG phải facade đọc đơn
 * (đã có `OrderQueryPort` cho việc ấy).
 *
 * **Vì sao method thứ hai trả DANH SÁCH ID chứ không trả tỉ lệ phần trăm.**
 * "Bao nhiêu phần trăm đơn liên lạc được" là chỉ số của DIỄN TẬP THU HỒI, thuộc
 * Inventory; còn "đơn này có đường liên lạc nào không" là sự thật về các cột của
 * `customer_orders`, thuộc Ordering. Trả về id để mỗi bên giữ đúng phần của mình
 * — đẩy phép chia sang đây là chuyển một chỉ số nghiệp vụ ra khỏi chủ của nó.
 */
interface OrderCustomerContacts
{
    /**
     * Khách đã đăng ký của từng đơn.
     *
     * Đơn của khách vãng lai / mua mang về không đăng nhập mang giá trị `null` —
     * và `null` KHÁC với "đơn không tồn tại": id không tra được thì không có khoá
     * trong mảng trả về. Người gọi phân biệt được hai ca đó, và `RecallService`
     * dựa vào chính điều này để đánh dấu đơn khách vãng lai là đã xử lý với
     * `notification_id = null`.
     *
     * @param  list<string>  $orderIds
     * @return array<string, string|null> khoá là id đơn, giá trị là id khách
     */
    public function customerIdsByOrderId(array $orderIds): array;

    /**
     * Tập con các đơn CÓ ít nhất một đường liên lạc với người mua — khách đã
     * đăng ký (`customer_id`) hoặc số điện thoại để lại khi mua mang về.
     *
     * Định nghĩa "liên lạc được" nằm ở đây chứ không ở Inventory: nó là danh
     * sách các CỘT của `customer_orders`, nên thêm một kênh liên lạc mới sau
     * này chỉ phải sửa một chỗ.
     *
     * @param  list<string>  $orderIds
     * @return list<string>
     */
    public function orderIdsWithReachableContact(array $orderIds): array;
}
