<?php

/**
 * FloatingSectionProduct Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\FloatingSectionProduct\Models\FloatingSectionProductBaseModel;
use Database\Factories\FloatingSectionProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * FloatingSectionProduct — add project-specific model logic here.
 */
class FloatingSectionProduct extends FloatingSectionProductBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): FloatingSectionProductFactory
    {
        return FloatingSectionProductFactory::new();
    }

    /**
     * Shop-level topping extra_price / visibility overrides for this floating
     * section product. Scoped to this shop entry — mirrors
     * MenuProduct::toppingOverrides (tier-1 of the 3-tier topping resolution).
     */
    public function toppingOverrides(): HasMany
    {
        return $this->hasMany(FloatingSectionProductToppingItemOverride::class, 'floating_section_product_id');
    }
}
