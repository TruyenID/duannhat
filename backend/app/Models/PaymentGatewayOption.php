<?php

/**
 * PaymentGatewayOption Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PaymentGatewayOption\Models\PaymentGatewayOptionBaseModel;
use Database\Factories\PaymentGatewayOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PaymentGatewayOption — add project-specific model logic here.
 */
class PaymentGatewayOption extends PaymentGatewayOptionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentGatewayOptionFactory
    {
        return PaymentGatewayOptionFactory::new();
    }

    //
}
