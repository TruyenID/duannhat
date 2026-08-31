<?php

/**
 * Product Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Product\Resources\ProductResourceBase;
use Illuminate\Http\Request;

/**
 * Adds the HQ-specific count + flag fields the Product list / detail screens
 * read directly. The base resource already serialises name/slug/status/etc.
 * and conditionally hydrates the productType/categories/options/skus
 * relations via whenLoaded.
 */
class ProductResource extends ProductResourceBase
{
    public function toArray(Request $request): array
    {
        $base = $this->schemaArray($request);
        $compact = $request->boolean('compact');

        // The generated base reads these relations directly. Replace gallery
        // with the conditional resource representation below, and omit the
        // legacy thumbnail blob from compact menu responses (image_url is the
        // canonical lightweight field used by menu clients).
        unset($base['gallery']);
        if ($compact) {
            unset($base['thumbnail']);
        }

        $skusCount = $this->whenCounted('skus');
        $optionsCount = $this->whenCounted('options');
        $activeSkusCount = $this->whenCounted('active_skus');

        return array_merge($base, [
            'skus_count' => $skusCount,
            'options_count' => $optionsCount,
            'active_skus_count' => $activeSkusCount,
            'has_default_sku_only' => is_int($skusCount) && is_int($optionsCount)
                ? ($optionsCount === 0 && $skusCount <= 1)
                : null,
            'deleted_at' => $this->deleted_at?->toISOString(),
            // gallery is only included when the relation was eager-loaded (show endpoint).
            // list() does not load gallery — omitting it keeps list responses lean.
            'gallery' => $this->when(
                ! $compact && $this->relationLoaded('gallery'),
                fn () => FileResource::collection($this->gallery),
            ),
            // Lightweight thumbnail URL for list / picker views. Resolves from
            // either `galleryFirst` (eager-loaded MorphOne, preferred for list
            // endpoints) or the first item of the full `gallery` collection
            // when present. Returns null when no gallery image exists.
            'image_url' => $this->resolveImageUrl(),
            // Workflow audit — exposed so the detail page can show rejection
            // reasons and approval timestamps under the ProductStatusBadge.
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at?->toISOString(),
            'rejected_at' => $this->rejected_at?->toISOString(),
            'approved_by_id' => $this->approved_by_id,
            'rejected_by_id' => $this->rejected_by_id,
            // Lightweight type marker for clients that branch on product
            // kind (POS combo badge, customer carousel) without pulling
            // the full ProductType resource. Returns the
            // ProductType.code (e.g. 'FOOD', 'DRINK', 'combo') when the
            // relation is eager-loaded; null when it isn't. The canonical
            // combo marker is the lowercase string 'combo' — see
            // BrandCoreCatalogService::ensureCombo.
            'product_type_code' => $this->whenLoaded('productType', fn () => $this->productType?->code),
            'topping_group_ids' => $this->whenLoaded('toppingGroups', fn () => $this->toppingGroups->pluck('id')),
            // Plan 015 — full topping_groups payload for shop-side menu
            // consumers (POS). HQ admin reads only `topping_group_ids`
            // because it doesn't need the items + per-SKU pricing chain.
            'topping_groups' => $this->whenLoaded(
                'toppingGroups',
                function () {
                    // Stamp product_id and shop-level topping overrides onto
                    // every ToppingGroupItem instance so MenuToppingGroupItemResource
                    // can apply the 3-tier priority chain without extra queries.
                    $productId = $this->id;
                    // Shop overrides stamped by MenuProductResource (null when
                    // this product is serialized outside a menu product context).
                    $shopOverrides = $this->resource->getAttribute('_shop_topping_overrides');

                    foreach ($this->toppingGroups as $group) {
                        if ($group->relationLoaded('items')) {
                            foreach ($group->items as $item) {
                                $item->setAttribute('_product_id', $productId);
                                $item->setAttribute('_shop_topping_overrides', $shopOverrides);
                            }
                        }
                    }

                    return MenuToppingGroupResource::collection($this->toppingGroups);
                }
            ),
        ]);
    }

    private function resolveImageUrl(): ?string
    {
        if ($this->relationLoaded('galleryFirst') && $this->galleryFirst) {
            return $this->galleryFirst->getUrl();
        }

        if ($this->relationLoaded('gallery') && $this->gallery->isNotEmpty()) {
            return $this->gallery->first()->getUrl();
        }

        return null;
    }
}
