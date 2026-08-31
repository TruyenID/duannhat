<?php

/**
 * PaymentGatewayProvider Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PaymentGatewayProvider\Models\PaymentGatewayProviderBaseModel;
use Database\Factories\PaymentGatewayProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PaymentGatewayProvider — add project-specific model logic here.
 */
class PaymentGatewayProvider extends PaymentGatewayProviderBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentGatewayProviderFactory
    {
        return PaymentGatewayProviderFactory::new();
    }

    //
}
