<?php

/**
 * ShopPaymentOption Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ShopPaymentOption\Models\ShopPaymentOptionBaseModel;
use Database\Factories\ShopPaymentOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ShopPaymentOption — add project-specific model logic here.
 */
class ShopPaymentOption extends ShopPaymentOptionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ShopPaymentOptionFactory
    {
        return ShopPaymentOptionFactory::new();
    }

    //
}
