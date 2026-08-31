<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One topping option inside a group, plus its per-SKU price ladder.
 *
 * `name` is read off the underlying `product` (each topping is a
 * Product with `product_type.code='topping'`). Soft-deleted items are
 * filtered upstream by the SoftDeletes scope.
 *
 * Plan 015 — Phase 2 read path.
 */
class MenuToppingGroupItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // ── 3-tier priority resolution ────────────────────────────────────
        //
        // Tier 1 (shop): menu_product_topping_item_overrides
        //   Stamped as `_shop_topping_overrides` collection by MenuProductResource
        //   → ProductResource → here. Scoped to a single branch menu product.
        //
        // Tier 2 (HQ per-product): product_topping_group_item_overrides
        //   Stamped as `_product_id` + eagerly loaded `productOverrides`.
        //
        // Tier 3 (HQ base): topping_group_item_skus.extra_price
        //   Direct column — used when no override exists.

        // Tier 1 — shop override.
        $shopOverrides = $this->resource->getAttribute('_shop_topping_overrides');
        $shopOverride = null;
        if ($shopOverrides !== null) {
            $shopOverride = $shopOverrides->first(
                fn ($ov) => $ov->topping_group_item_id === $this->id
            );
        }

        // Tier 2 — HQ per-product override (only checked when no shop override).
        $hqOverride = null;
        if ($shopOverride === null) {
            $productId = $this->resource->getAttribute('_product_id');
            if ($productId && $this->relationLoaded('productOverrides')) {
                $hqOverride = $this->productOverrides
                    ->firstWhere('product_id', $productId);
            }
        }

        // Effective override: shop wins over HQ.
        $effectiveOverride = $shopOverride ?? $hqOverride;

        // Stamp effective_extra_price on matching SKU rows so
        // MenuToppingGroupItemSkuResource serializes the right price.
        // Wildcard override (product_sku_id IS NULL) applies to every SKU;
        // a scoped override (product_sku_id NOT NULL) applies only to that SKU.
        if ($this->relationLoaded('skus') && $effectiveOverride?->override_price !== null) {
            foreach ($this->skus as $sku) {
                if ($effectiveOverride->product_sku_id === null
                    || $effectiveOverride->product_sku_id === $sku->product_sku_id) {
                    $sku->effective_extra_price = $effectiveOverride->override_price;
                }
            }
        }

        // Stamp whether a wildcard row still prices anything, for the same
        // reason effective_extra_price is stamped here: the child resource sees
        // one row, and this question can only be answered from the item (it
        // needs the topping product's SKU set plus every sibling row).
        //
        // Computed ONCE per item — the predicate is cheap only while the
        // relations are loaded, and the admin menu screen renders dozens of
        // items. Wildcard rows are at most one per item (createItemSku enforces
        // that), so one call covers the collection.
        if ($this->relationLoaded('skus')) {
            $wildcard = $this->skus->first(
                fn ($sku): bool => $sku->product_sku_id === null
            );
            if ($wildcard !== null) {
                $wildcard->applies = $this->resource->wildcardPriceApplies();
            }
        }

        // is_hidden: shop override takes precedence over HQ override.
        //
        // plan-056 — "ANY shop row hides it", not "the first row decides".
        //
        // `$shopOverride` above is `->first(...)` over an UNORDERED collection,
        // so with several rows for one item (a wildcard plus a per-SKU one, the
        // normal shape once a shop prices one variant differently) the answer
        // depended on which row the DB happened to return first: the same data
        // could show the topping or hide it. Nobody noticed while only admin-web
        // wrote these rows and always rewrote the whole set.
        //
        // The POS availability screen makes that reachable in one tap, so the
        // rule is now explicit and order-free. It resolves toward HIDING: a
        // shop that marked a topping unavailable anywhere meant it, and serving
        // something they marked gone is the worse mistake.
        $shopHides = $shopOverrides !== null
            && $shopOverrides->contains(
                fn ($ov) => $ov->topping_group_item_id === $this->id && (bool) $ov->is_hidden,
            );

        $isHidden = $shopHides || (bool) ($effectiveOverride?->is_hidden ?? false);

        return [
            'id' => $this->id,
            'topping_group_id' => $this->topping_group_id,
            'product_id' => $this->product_id,
            'name' => $this->whenLoaded('product', fn () => $this->product->name),
            'image_url' => $this->when(
                $this->relationLoaded('product')
                    && $this->product?->relationLoaded('galleryFirst'),
                fn () => $this->product?->galleryFirst?->getUrl(),
            ),
            'is_default' => (bool) $this->is_default,
            'is_hidden' => $isHidden,
            'sort_order' => (int) $this->sort_order,
            'skus' => $this->when(
                $this->relationLoaded('skus'),
                fn () => MenuToppingGroupItemSkuResource::collection($this->skus),
            ),
            'options' => $this->when(
                $this->relationLoaded('product') && $this->product?->relationLoaded('options'),
                fn () => $this->product->options->map(fn ($option) => [
                    'id' => $option->id,
                    'name' => $option->name,
                    'type' => $option->type,
                    'required' => (bool) $option->is_required,
                    'max' => $option->max_selections,
                    'variants' => $option->relationLoaded('values') ? $option->values->map(fn ($value) => [
                        'id' => $value->id,
                        'name' => $value->name,
                        'price' => (float) ($value->extra_price ?? 0),
                        'default' => (bool) ($value->is_default ?? false),
                        'image' => null,
                    ])->toArray() : [],
                ]),
            ),
        ];
    }
}
