<?php

/**
 * FloatingSectionProductToppingItemOverride Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\FloatingSectionProductToppingItemOverride\Models\FloatingSectionProductToppingItemOverrideBaseModel;
use Database\Factories\FloatingSectionProductToppingItemOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * FloatingSectionProductToppingItemOverride — add project-specific model logic here.
 */
class FloatingSectionProductToppingItemOverride extends FloatingSectionProductToppingItemOverrideBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): FloatingSectionProductToppingItemOverrideFactory
    {
        return FloatingSectionProductToppingItemOverrideFactory::new();
    }

    //
}
