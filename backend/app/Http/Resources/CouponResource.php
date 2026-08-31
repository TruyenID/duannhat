<?php

/**
 * Coupon Resource — plan-019.
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Coupon\Resources\CouponResourceBase;
use App\Services\Promotion\CouponService;
use Illuminate\Http\Request;

class CouponResource extends CouponResourceBase
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Plan-019 — derived computed_status for the FE list filter.
        $data['computed_status'] = app(CouponService::class)->computeStatus($this->resource);

        // remaining_uses convenience for HQ list — null when unlimited.
        $limit = $this->resource->usage_limit_total;
        $data['remaining_uses'] = $limit === null
            ? null
            : max(0, (int) $limit - (int) $this->resource->times_used);

        // applicable_branch_ids — empty array means brand-wide.
        $data['applicable_branch_ids'] = $this->resource->relationLoaded('branches')
            ? $this->resource->branches->pluck('id')->all()
            : [];

        // active_redemptions_count — populated by withCount on list/find queries.
        $data['active_redemptions_count'] = $this->resource->active_redemptions_count ?? null;

        return $data;
    }
}
