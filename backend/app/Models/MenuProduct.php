<?php

/**
 * MenuProduct Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuProduct\Models\MenuProductBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\MenuProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuProduct extends MenuProductBaseModel
{
    // AUDIT FIX 3.9 (2026-07-14): MenuProduct carries a consumption-tax
    // override (tax_type_id, plan-043 tier-1) but had NO audit trail — a
    // rate-affecting change left no record of who/when. Product already
    // audits; the menu-level override must too.
    use AuditsActivity;
    use HasFactory;

    protected static function newFactory(): MenuProductFactory
    {
        return MenuProductFactory::new();
    }

    /**
     * Shop-level topping extra_price / visibility overrides for this menu product.
     * Scoped to this branch entry — does not affect other branches or HQ.
     */
    public function toppingOverrides(): HasMany
    {
        return $this->hasMany(MenuProductToppingItemOverride::class, 'menu_product_id');
    }
}
