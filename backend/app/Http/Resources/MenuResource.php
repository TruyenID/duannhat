<?php

/**
 * Menu Resource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\Menu\Resources\MenuResourceBase;
use Illuminate\Http\Request;

/**
 * MenuResource — add project-specific serialization here.
 *
 * Inherited from base:
 *   - toArray(Request \$request): array  (returns schemaArray(\$request) — override to add fields)
 */
class MenuResource extends MenuResourceBase
{
    public function toArray(Request $request): array
    {
        $base = $this->schemaArray($request);

        // The generated base resource exposes this relationship as
        // `menuProducts`, while the established shop API contract is
        // `menu_products`. Sending both duplicates the entire deeply nested
        // catalog (over 2 MB for Ningyocho) through the Amplify proxy.
        unset($base['menuProducts']);

        return array_merge($base, [
            'menu_products_count' => $this->when(
                array_key_exists('menu_products_count', $this->resource->getAttributes()),
                fn () => $this->menu_products_count,
            ),
            'cloned_menus_count' => $this->when(
                array_key_exists('cloned_menus_count', $this->resource->getAttributes()),
                fn () => $this->cloned_menus_count,
            ),
            'menu_products' => $this->when(
                $this->relationLoaded('menuProducts'),
                fn () => MenuProductResource::collection($this->menuProducts),
            ),
            // #1227 — the whole-menu tax tier's RATE (the id alone means nothing
            // to a screen), plus each section's rate keyed by section id. Both
            // are stamped by Shop\MenuController::stampEffectiveTaxRates and are
            // absent elsewhere, so the shop screen can show where a line's rate
            // came from without re-walking the tiers client-side.
            'tax_rate' => $this->resource->getAttribute('tax_rate'),
            'section_tax_rates' => $this->resource->getAttribute('section_tax_rates'),

            // HQ context: expose the per-menu override + brand default.
            'cart_timeout_minutes' => $this->cart_timeout_minutes,
            'hq_brand_timeout_minutes' => $this->when(
                $this->relationLoaded('brand'),
                fn () => $this->brand?->cart_timeout_minutes,
                fn () => $this->resource->brand?->cart_timeout_minutes,
            ),

            // has_schedules — true when this menu has at least one schedule row.
            'has_schedules' => array_key_exists('schedules_count', $this->resource->getAttributes())
                ? (bool) $this->schedules_count
                : null,

            // Shop context: full inheritance chain, only present when masterMenu is eager-loaded.
            // branch menu.cart_timeout_minutes    = tầng ④ shop per-menu override
            // masterMenu.cart_timeout_minutes     = tầng ② HQ per-menu
            // branch.cart_timeout_minutes         = tầng ③ shop default
            // masterMenu.brand.cart_timeout_minutes = tầng ① HQ brand default
            'hq_menu_timeout_minutes' => $this->when(
                $this->relationLoaded('masterMenu'),
                fn () => $this->masterMenu?->cart_timeout_minutes,
            ),
            'shop_default_timeout_minutes' => $this->when(
                $this->relationLoaded('masterMenu'),
                fn () => $this->resource->branch?->cart_timeout_minutes,
            ),
            'shop_menu_timeout_minutes' => $this->when(
                $this->relationLoaded('masterMenu'),
                fn () => $this->cart_timeout_minutes,
            ),
            'effective_timeout_minutes' => $this->when(
                $this->relationLoaded('masterMenu'),
                fn () => $this->cart_timeout_minutes                              // tầng ④
                    ?? $this->resource->branch?->cart_timeout_minutes            // tầng ③
                    ?? $this->masterMenu?->cart_timeout_minutes                  // tầng ②
                    ?? $this->masterMenu?->brand?->cart_timeout_minutes,         // tầng ①
            ),

            // Service-type inheritance (#463): shop can inherit the master (HQ)
            // menu's type live (branch service_type = null) or override it.
            'hq_service_type' => $this->when(
                $this->relationLoaded('masterMenu'),
                fn () => $this->masterMenu?->service_type,
            ),
            'shop_service_type' => $this->when(
                $this->relationLoaded('masterMenu'),
                fn () => $this->service_type,
            ),
            // #1756 — the POS listings need this too, but they resolve the
            // master's value through the `master_service_type` scalar alias
            // (MenuService::masterServiceTypeSubquery) rather than by loading
            // the relation, so accept either source. Raw `service_type` alone
            // is not enough for a screen: NULL there means "inherit", not a
            // service type.
            'effective_service_type' => $this->when(
                $this->relationLoaded('masterMenu')
                    || array_key_exists('master_service_type', $this->resource->getAttributes()),
                fn () => $this->service_type
                    ?? $this->inheritedServiceType()
                    ?? 'Both',
            ),
        ]);
    }

    /**
     * The master (HQ) menu's service type, from whichever source the caller
     * arranged — never by triggering a lazy load, which on a paginated listing
     * would be one query per row.
     */
    private function inheritedServiceType(): ?string
    {
        if (array_key_exists('master_service_type', $this->resource->getAttributes())) {
            return $this->resource->getAttribute('master_service_type');
        }

        return $this->masterMenu?->service_type;
    }
}
