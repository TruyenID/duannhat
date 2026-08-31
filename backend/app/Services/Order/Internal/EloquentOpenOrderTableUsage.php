<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Order\Contracts\OpenOrderTableUsage;

/**
 * #962 (7b) — hiện thực {@see OpenOrderTableUsage}.
 *
 * Chép NGUYÊN vị từ của `TableService::delete()` / `ZoneService::delete()`, kể cả
 * việc dùng scope `open()` thay vì liệt kê trạng thái — scope đó LÀ định nghĩa
 * "chưa đóng" của Ordering (xem {@see EloquentCustomerOrderPresence}).
 */
final class EloquentOpenOrderTableUsage implements OpenOrderTableUsage
{
    public function anyOpenOrderUsesTables(array $tableIds): bool
    {
        if ($tableIds === []) {
            return false;
        }

        return CustomerOrder::open()->whereIn('table_id', $tableIds)->exists();
    }
}
