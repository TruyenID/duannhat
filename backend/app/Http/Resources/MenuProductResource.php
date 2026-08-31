<?php

/**
 * MenuProduct Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\MenuProduct\Resources\MenuProductResourceBase;
use Illuminate\Http\Request;

/**
 * MenuProductResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class MenuProductResource extends MenuProductResourceBase
{
    public function toArray(Request $request): array
    {
        $base = $this->schemaArray($request);

        // The generated relationship names duplicate the established shop API
        // aliases below. On large menus this needlessly sends every SKU and
        // section twice, which can push the response over reverse-proxy limits.
        unset($base['menuProductSkus'], $base['menuSection']);

        return array_merge($base, [
            'skus' => $this->when(
                $this->relationLoaded('menuProductSkus'),
                fn () => MenuProductSkuResource::collection($this->menuProductSkus),
            ),
            'product' => $this->when(
                // Guard null: menu rows can reference a product that was
                // soft-deleted later — render without a product blob instead
                // of 500ing on setAttribute(null).
                $this->relationLoaded('product') && $this->product !== null,
                function () {
                    // Stamp menu_product_id + preloaded shop topping overrides
                    // onto the Product instance so ProductResource →
                    // MenuToppingGroupItemResource can apply the 3-tier
                    // priority (shop → HQ → base extra_price) without extra queries.
                    $this->product->setAttribute('_menu_product_id', $this->id);
                    $this->product->setAttribute(
                        '_shop_topping_overrides',
                        $this->resource->relationLoaded('toppingOverrides')
                            ? $this->resource->toppingOverrides
                            : collect(),
                    );

                    return new ProductResource($this->product);
                },
            ),
            'section' => $this->when(
                $this->relationLoaded('menuSection') && $this->menuSection !== null,
                fn () => [
                    'id' => $this->menuSection->id,
                    'name' => $this->menuSection->name,
                ],
            ),
            // Plan-019 — Happy Hour overlay attached by Shop\MenuController
            // ::listProducts (batch-resolved via MenuPromotionService). Null
            // when the product isn't in any active promotion right now.
            'active_promotion' => $this->resource->getAttribute('active_promotion_overlay'),
            // #1227 — the rate this line is actually billed at, resolved by
            // TaxResolver across all six tiers and stamped by Shop\MenuController
            // ::stampEffectiveTaxRates. `tax_type_id` above is only this line's
            // own override; on its own it cannot tell the shop what a customer
            // pays, because the section and menu tiers sit between it and the
            // product. Null when the shop endpoint did not stamp it.
            'effective_tax_rate' => $this->resource->getAttribute('effective_tax_rate'),
        ]);
    }
}
