<?php

/**
 * FloatingSectionProductResource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\FloatingSectionProduct\Resources\FloatingSectionProductResourceBase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class FloatingSectionProductResource extends FloatingSectionProductResourceBase
{
    /**
     * Stamp the tier-1 shop topping overrides onto the embedded product before
     * the base resource serializes it — mirrors MenuProductResource so
     * ProductResource → MenuToppingGroupItemResource applies the same 3-tier
     * priority (shop override → HQ per-product override → base extra_price)
     * with no extra queries. The item resource resolves purely off the
     * `_shop_topping_overrides` collection, so no owner-id stamp is needed.
     */
    public function toArray(Request $request): array
    {
        if ($this->relationLoaded('product') && $this->product !== null) {
            $this->product->setAttribute(
                '_shop_topping_overrides',
                $this->resource->relationLoaded('toppingOverrides')
                    ? $this->resource->toppingOverrides
                    : new Collection,
            );
        }

        return $this->schemaArray($request);
    }
}
