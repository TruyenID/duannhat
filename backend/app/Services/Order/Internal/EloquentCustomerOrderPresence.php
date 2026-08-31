<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Order\Contracts\CustomerOrderPresence;

/**
 * #1596 — hiện thực {@see CustomerOrderPresence}.
 *
 * Chép nguyên truy vấn của `CustomerService::delete()`, kể cả việc dùng scope
 * `open()` của model thay vì liệt kê trạng thái: scope đó là định nghĩa "chưa
 * đóng" của Ordering, và chép danh sách trạng thái ra đây sẽ tạo bản sao thứ hai
 * lệch được với bản gốc.
 */
final class EloquentCustomerOrderPresence implements CustomerOrderPresence
{
    public function hasOpenOrder(string $customerId): bool
    {
        return CustomerOrder::open()->where('customer_id', $customerId)->exists();
    }
}
