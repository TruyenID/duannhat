<?php

/**
 * MenuPromotion Resource — plan-019.
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\MenuPromotion\Resources\MenuPromotionResourceBase;
use App\Services\Promotion\MenuPromotionService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class MenuPromotionResource extends MenuPromotionResourceBase
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);

        // Plan-019 — applicable_category_ids + applicable_product_ids
        // are the M2M pivot mirrors the FE form rebinds on edit.
        $data['applicable_category_ids'] = $this->resource->relationLoaded('categories')
            ? $this->resource->categories->pluck('id')->all()
            : [];
        $data['applicable_product_ids'] = $this->resource->relationLoaded('products')
            ? $this->resource->products->pluck('id')->all()
            : [];

        // Derived "currently_active" flag — true iff is_active AND inside
        // valid_from..valid_until at request time. Daily window + weekday
        // is NOT considered here (the list page uses `currently_active`
        // for the colored Badge; intra-day aliveness is handled at the
        // resolver).
        $now = CarbonImmutable::now();
        $data['currently_active'] = (bool) $this->resource->is_active
            && $now->gte($this->resource->valid_from)
            && $now->lte($this->resource->valid_until);

        // Plan-019 — flat branch identifiers for the HQ cross-shop list.
        // The branch relation is eager-loaded by service->list() when
        // available; falls back to id only otherwise.
        if ($this->resource->relationLoaded('branch') && $this->resource->branch !== null) {
            $data['branch_slug'] = $this->resource->branch->slug;
            $data['branch_name'] = $this->resource->branch->name;
        }

        // Optional report block — present when `with_report=true` was
        // passed to service->list(). withCount always materializes an int
        // (`0` for no rows), but withSum returns null when nothing matched
        // so we must coalesce to 0.0 ourselves. Both flat fields and the
        // nested `report` block are emitted so shop detail page + HQ list
        // can read the same shape.
        $reportLoaded = isset($this->resource->items_with_promotion_count);
        if ($reportLoaded) {
            $itemsCount = (int) $this->resource->items_with_promotion_count;
            $totalDiscount = (float) ($this->resource->total_discount_amount ?? 0);

            $data['items_with_promotion_count'] = $itemsCount;
            $data['total_discount_amount'] = $totalDiscount;
            $data['report'] = ($data['report'] ?? []) + [
                'items_with_promotion_count' => $itemsCount,
                'total_discount_applied' => $totalDiscount,
            ];
        }

        return $data;
    }

    /**
     * Bundle the optional report block when explicitly requested.
     *
     * @return array<string, mixed>
     */
    public function withReport(): array
    {
        return [
            'report' => app(MenuPromotionService::class)->report($this->resource),
        ];
    }
}
