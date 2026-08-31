<?php

/**
 * PaymentGatewayConnectionOption Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PaymentGatewayConnectionOption\Models\PaymentGatewayConnectionOptionBaseModel;
use Database\Factories\PaymentGatewayConnectionOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PaymentGatewayConnectionOption — add project-specific model logic here.
 */
class PaymentGatewayConnectionOption extends PaymentGatewayConnectionOptionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentGatewayConnectionOptionFactory
    {
        return PaymentGatewayConnectionOptionFactory::new();
    }

    //
}
