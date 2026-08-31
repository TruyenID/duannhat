<?php

/**
 * ProductToppingGroupItemOverride Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ProductToppingGroupItemOverride\Models\ProductToppingGroupItemOverrideBaseModel;
use Database\Factories\ProductToppingGroupItemOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ProductToppingGroupItemOverride — add project-specific model logic here.
 */
class ProductToppingGroupItemOverride extends ProductToppingGroupItemOverrideBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ProductToppingGroupItemOverrideFactory
    {
        return ProductToppingGroupItemOverrideFactory::new();
    }

    //
}
