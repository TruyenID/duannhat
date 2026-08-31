<?php

/**
 * FloatingSectionProductToppingItemOverride Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mirrors MenuProductToppingItemOverrideResource's flat shape (is_hidden bool,
 * override_price string|null) so the shop FE consumes both override endpoints
 * identically.
 */
class FloatingSectionProductToppingItemOverrideResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'floating_section_product_id' => $this->floating_section_product_id,
            'topping_group_id' => $this->topping_group_id,
            'topping_group_item_id' => $this->topping_group_item_id,
            'product_sku_id' => $this->product_sku_id,
            'is_hidden' => (bool) $this->is_hidden,
            'override_price' => $this->override_price !== null ? (string) $this->override_price : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
