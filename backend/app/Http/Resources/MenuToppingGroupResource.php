<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Topping group as it appears on a shop-side menu product.
 *
 * Source: ToppingGroup model joined to its parent Product through the
 * `product_topping_groups` pivot. The `pivot.{min,max}_select_override`
 * columns are folded into `effective_min_select` / `effective_max_select`
 * here so the frontend never has to reapply COALESCE.
 *
 * Plan 015 — Phase 2 read path. Schedule fields (`available_*`) are
 * passed through unchanged for staff visibility / debugging; the
 * actual schedule filter is applied server-side in MenuService before
 * the relation is loaded, so groups that are out-of-window never reach
 * this resource.
 */
class MenuToppingGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'selection_type' => $this->selection_type instanceof \BackedEnum
                ? $this->selection_type->value
                : $this->selection_type,
            'modifier_type' => $this->modifier_type instanceof \BackedEnum
                ? $this->modifier_type->value
                : $this->modifier_type,
            'price_strategy' => $this->price_strategy instanceof \BackedEnum
                ? $this->price_strategy->value
                : $this->price_strategy,
            'free_quantity' => $this->free_quantity,
            'min_select' => (int) $this->min_select,
            'max_select' => $this->max_select === null ? null : (int) $this->max_select,
            // Column dropped in migration 2000_02_13 — YAML schema notes Phase 1
            // defaults to 1. pos-web's product-options-dialog uses this to clamp
            // the per-selection quantity stepper; without the explicit field the
            // frontend reads undefined → Math.min(undefined, …) = NaN.
            'max_qty_per_item' => (int) ($this->max_qty_per_item ?? 1),
            // Effective bounds folded from the per-product override pivot —
            // pivot.X_select_override beats group.X_select. NULL pivot ⇒
            // pass through the group default.
            'effective_min_select' => $this->resolveEffectiveMin(),
            'effective_max_select' => $this->resolveEffectiveMax(),
            'sort_order' => $this->whenPivotLoaded('product_topping_groups', fn () => (int) $this->pivot->sort_order),
            'is_active' => (bool) $this->is_active,
            'items' => $this->when(
                $this->relationLoaded('items'),
                fn () => MenuToppingGroupItemResource::collection($this->items),
            ),
        ];
    }

    private function resolveEffectiveMin(): int
    {
        if ($this->relationLoaded('pivot') || $this->pivot !== null) {
            $override = $this->pivot?->min_select_override;
            if ($override !== null) {
                return (int) $override;
            }
        }

        return (int) $this->min_select;
    }

    private function resolveEffectiveMax(): ?int
    {
        if ($this->relationLoaded('pivot') || $this->pivot !== null) {
            $override = $this->pivot?->max_select_override;
            if ($override !== null) {
                return (int) $override;
            }
        }

        return $this->max_select === null ? null : (int) $this->max_select;
    }
}
