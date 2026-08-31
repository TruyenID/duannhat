<?php

/**
 * OrderPaymentIntent Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\OrderPaymentIntent\Models\OrderPaymentIntentBaseModel;
use Database\Factories\OrderPaymentIntentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * OrderPaymentIntent — add project-specific model logic here.
 */
class OrderPaymentIntent extends OrderPaymentIntentBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): OrderPaymentIntentFactory
    {
        return OrderPaymentIntentFactory::new();
    }

    //
}
