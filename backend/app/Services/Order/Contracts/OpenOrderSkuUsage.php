<?php

declare(strict_types=1);

namespace App\Services\Order\Contracts;

/**
 * #1622 — cổng Ordering công bố cho Catalog: *"SKU này có đang nằm trong một đơn
 * CHƯA ĐÓNG không?"*
 *
 * Trước bản vá này Catalog tự trả lời bằng cách **đọc thẳng ba bảng của
 * Ordering** (`customer_order_items`, `order_item_toppings`, `customer_orders`)
 * qua query builder thô. Deptrac không thấy — không import class nào — nên nó
 * chưa từng nằm trong con số nào của #962; chỉ `architecture:raw-table-reads`
 * (#1621) mới đo được, và mãi tới #1625 mới thấy vì hai truy vấn đó **đặt bí
 * danh**.
 *
 * Đáng chú ý: `ProductSkuService::skuInOpenOrder()` đã được dọn MỘT LẦN ở #1596
 * — đổi `CustomerOrder::OPEN_STATUSES` sang {@see OrderStatusVocabulary::OPEN}.
 * Lần đó gỡ được **hằng số**, nhưng ba bảng bên dưới vẫn bị đọc thẳng và không
 * phép đo nào nói ra. Một lời nhắc rằng "class không còn import model" chưa có
 * nghĩa là "module thôi chạm dữ liệu của module khác".
 *
 * Câu hỏi thuộc về **Ordering**: nó biết trạng thái nào là "chưa đóng" và món
 * nằm ở đâu trong đơn. Catalog chỉ cần câu trả lời để quyết có cho xoá SKU hay
 * không (plan-042).
 */
interface OpenOrderSkuUsage
{
    /**
     * Có đơn CHƯA ĐÓNG nào tham chiếu một trong các SKU này không — dù là dòng
     * món hay topping?
     *
     * Trả `bool` chứ không trả danh sách đơn: chỗ gọi duy nhất chỉ cần chặn hay
     * cho qua, và trả về danh sách sẽ mời người sau đọc chi tiết đơn hàng từ
     * Catalog — đúng thứ cổng này tồn tại để chặn.
     *
     * @param  list<string>  $skuIds
     */
    public function anyOpenOrderUsesSkus(array $skuIds): bool;
}
