<?php

namespace App\Http\Resources;

use App\Omnify\Modules\CouponRedemption\Resources\CouponRedemptionResourceBase;
use Illuminate\Http\Request;

/**
 * Plan-019 — flat-field projection of CouponRedemption for the HQ coupon
 * detail "Redemption history" tab.
 *
 * Adds `customer_name` (resolved from the eager-loaded customer relation —
 * `null` for walk-ins) and `order_code` (from customerOrder) so the FE
 * table can render rows directly without traversing nested resources. The
 * inherited base still exposes `coupon`, `customer`, `customerOrder` under
 * `whenLoaded` for callers that need the full nested objects.
 */
class CouponRedemptionResource extends CouponRedemptionResourceBase
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $base = parent::toArray($request);

        $customerName = null;
        if ($this->resource->relationLoaded('customer') && $this->customer !== null) {
            $customerName = trim(
                ($this->customer->first_name ?? '').' '.($this->customer->last_name ?? '')
            );
            $customerName = $customerName !== '' ? $customerName : null;
        }

        $orderCode = null;
        if ($this->resource->relationLoaded('customerOrder') && $this->customerOrder !== null) {
            $orderCode = $this->customerOrder->order_code;
        }

        return array_merge($base, [
            'customer_name' => $customerName,
            'order_code' => $orderCode,
        ]);
    }
}
