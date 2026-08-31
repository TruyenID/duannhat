<?php

declare(strict_types=1);

namespace App\Services\Promotion\Internal;

use App\Services\Promotion\Contracts\CustomerCouponReassignment;
use Illuminate\Support\Facades\DB;

/**
 * #1550 — người ghi `coupon_redemptions` + `coupons` cho lượt gộp khách.
 *
 * Khai là biên của aggregate `promotion`; lý do và hình dạng: xem
 * `Order\Internal\EloquentCustomerOrderReassignment`.
 */
final class EloquentCustomerCouponReassignment implements CustomerCouponReassignment
{
    public function reassignCustomer(string $sourceCustomerId, string $targetCustomerId): int
    {
        return DB::table('coupon_redemptions')
            ->where('customer_id', $sourceCustomerId)
            ->update(['customer_id' => $targetCustomerId])
            + DB::table('coupons')
                ->where('customer_id', $sourceCustomerId)
                ->update(['customer_id' => $targetCustomerId]);
    }
}
