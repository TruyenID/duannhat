<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Per-SKU price override row for a topping option.
 *
 * `product_sku_id` MAY be null — that's the simple-topping fallback row,
 * meaning "this extra_price applies regardless of which variant of the
 * topping product the customer picks". POS sends a real SKU id back on
 * addItems (Phase 2 contract); the resolver in
 * `ToppingPricingService::resolveSnapshotPrice` falls back to the NULL
 * row when no per-SKU row matches.
 *
 * `extra_price` serialises the effective price: when MenuToppingGroupItemResource
 * has stamped `effective_extra_price` onto this model (because a
 * product_topping_group_item_override exists with a non-null override_price),
 * that value is used instead of the HQ base extra_price.
 *
 * Plan 015 — Phase 2 read path.
 */
class MenuToppingGroupItemSkuResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Use the effective price stamped by MenuToppingGroupItemResource when
        // a product-level override exists; fall back to the HQ base extra_price.
        $effectivePrice = $this->resource->effective_extra_price ?? $this->extra_price;

        return [
            'id' => $this->id,
            'topping_group_item_id' => $this->topping_group_item_id,
            'product_sku_id' => $this->product_sku_id,
            'extra_price' => (string) $effectivePrice,
            // The HQ base extra_price BEFORE any shop/HQ override is applied.
            // `extra_price` above collapses to the effective (overridden) price,
            // so the admin Shop→Menu topping screen needs this untouched base to
            // render the "default price" column alongside the override column
            // (otherwise a saved override overwrites the displayed base on
            // reload — the two columns would show the same number).
            'base_extra_price' => (string) $this->extra_price,
            // Variant label from option values ("Ít / Nongs"), the HQ-product
            // convention. product_skus.name is null for option-based variants,
            // so serving it made the admin show a raw SKU code instead.
            'sku_label' => $this->whenLoaded('productSku', fn () => $this->productSku?->variant_label),
            'sku_code' => $this->whenLoaded('productSku', fn () => $this->productSku?->sku),
            // Catalog-level availability of the underlying variant. A wildcard
            // row (product_sku_id NULL) has no ProductSku and is always
            // orderable → true. Admin surfaces this so the badge cannot show
            // "Active" for a variant the customer menu hides (an HQ-disabled
            // SKU); is_hidden (shop override) is a SEPARATE flag layered on top.
            'is_active' => $this->product_sku_id === null
                ? true
                : (bool) ($this->whenLoaded('productSku', fn () => $this->productSku?->is_active) ?? true),
            // Does this row still price anything? Only ever false for a wildcard
            // row (product_sku_id NULL) whose every active SKU has since gained
            // a scoped row of its own — the resolver then never reaches it.
            //
            // Admin needs this because such a row was drawn as an ordinary line
            // in the "variants" table, labelled with the topping's own name, so
            // the operator read "two variants" off a topping that sells one
            // (#1275 → #1316). Since #1277 refuses to create these, the screen
            // must also be able to say why an existing one does nothing —
            // otherwise re-saving it just returns 422 with no explanation.
            //
            // Defaults to TRUE when the parent resource could not compute it
            // (relations not loaded): a row that works must never be labelled
            // dead. Under-reporting is the safe direction here.
            'applies' => $this->product_sku_id !== null
                ? true
                : (bool) ($this->resource->getAttribute('applies') ?? true),
        ];
    }
}
