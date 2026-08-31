<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Services\Order\Contracts\CustomerOrderReassignment;
use Illuminate\Support\Facades\DB;

/**
 * #1550 — người ghi `customer_orders` cho lượt gộp khách.
 *
 * Nằm trong `Order/Internal` và được khai là biên của aggregate `order` trong
 * `config/domain-mutation-guard.php`, nên `architecture:domain-writers` thấy
 * một cửa ghi CÓ CHỦ với tên bảng là hằng — khác hẳn `DB::table($biến)` ở phía
 * CustomerEngagement mà rào chỉ đọc được là `dynamic-table`.
 *
 * Cố ý là query builder chứ không phải model: đây là một `UPDATE` khối trên
 * khoá ngoại, không phải một đột biến đơn hàng. Nạp từng đơn ra để `save()` sẽ
 * kích hoạt observer của đơn (bơm sự kiện, đánh dấu bản sửa) cho một thao tác
 * KHÔNG hề đổi nội dung đơn nào — chỉ đổi chủ sở hữu hồ sơ khách.
 */
final class EloquentCustomerOrderReassignment implements CustomerOrderReassignment
{
    public function reassignCustomer(string $sourceCustomerId, string $targetCustomerId): int
    {
        return DB::table('customer_orders')
            ->where('customer_id', $sourceCustomerId)
            ->update(['customer_id' => $targetCustomerId]);
    }
}
