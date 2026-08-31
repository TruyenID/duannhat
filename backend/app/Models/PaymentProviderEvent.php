<?php

/**
 * PaymentProviderEvent Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PaymentProviderEvent\Models\PaymentProviderEventBaseModel;
use Database\Factories\PaymentProviderEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PaymentProviderEvent — add project-specific model logic here.
 */
class PaymentProviderEvent extends PaymentProviderEventBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentProviderEventFactory
    {
        return PaymentProviderEventFactory::new();
    }

    //
}
