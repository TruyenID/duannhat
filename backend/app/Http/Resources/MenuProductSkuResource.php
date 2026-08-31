<?php

/**
 * MenuProductSku Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\MenuProductSku\Resources\MenuProductSkuResourceBase;
use Illuminate\Http\Request;

/**
 * MenuProductSkuResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class MenuProductSkuResource extends MenuProductSkuResourceBase
{
    public function toArray(Request $request): array
    {
        return array_merge($this->schemaArray($request), [
            'default_price' => $this->when(
                $this->relationLoaded('productSku') && $this->is_price_overridden,
                fn () => $this->productSku?->selling_price,
            ),
            'product_sku' => $this->when(
                $this->relationLoaded('productSku'),
                fn () => new ProductSkuResource($this->productSku),
            ),
            // Runtime sale price resolved by Shop\MenuController. Keep
            // selling_price as the editable menu price on management screens.
            'effective_selling_price' => $this->resource->getAttribute('effective_selling_price'),
            'active_floating_section' => $this->resource->getAttribute('active_floating_section'),
        ]);
    }
}
