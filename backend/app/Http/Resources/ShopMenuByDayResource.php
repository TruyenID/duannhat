<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Wraps MenuResource and surfaces `start_time` / `end_time` of the highest-priority
 * `menu_schedules` row matching the requested day-of-week. Times are pulled inline
 * by `MenuService::listActiveBranchMenusForShopByDay()` via correlated subqueries
 * and arrive on the model as `matched_start_time` / `matched_end_time` aliases —
 * no per-row eager-load.
 *
 * The times are shop-effective: any `branch_schedule_overrides` row for the winning
 * schedule/branch pair supersedes the HQ default via COALESCE. Consumers (POS,
 * handy, workstation) can trust these values as-is without re-applying overrides.
 *
 * The service guarantees every row in the response has a matching schedule, so
 * these fields are never null.
 */
class ShopMenuByDayResource extends MenuResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'start_time' => $this->resource->getAttribute('matched_start_time'),
            'end_time' => $this->resource->getAttribute('matched_end_time'),
        ]);
    }
}
