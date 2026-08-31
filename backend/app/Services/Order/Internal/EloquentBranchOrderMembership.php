<?php

declare(strict_types=1);

namespace App\Services\Order\Internal;

use App\Models\CustomerOrder;
use App\Services\Order\Contracts\BranchOrderMembership;

/**
 * Hiện thực {@see BranchOrderMembership} — sống trong Ordering vì đây là module
 * SỞ HỮU `customer_orders`.
 */
final class EloquentBranchOrderMembership implements BranchOrderMembership
{
    public function orderBelongsToBranch(string $orderId, string $branchId): bool
    {
        return CustomerOrder::query()
            ->whereKey($orderId)
            ->where('branch_id', $branchId)
            ->exists();
    }
}
