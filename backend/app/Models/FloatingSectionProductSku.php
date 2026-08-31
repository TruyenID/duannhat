<?php

/**
 * FloatingSectionProductSku Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\FloatingSectionProductSku\Models\FloatingSectionProductSkuBaseModel;
use Database\Factories\FloatingSectionProductSkuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * FloatingSectionProductSku — add project-specific model logic here.
 */
class FloatingSectionProductSku extends FloatingSectionProductSkuBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): FloatingSectionProductSkuFactory
    {
        return FloatingSectionProductSkuFactory::new();
    }

    //
}
