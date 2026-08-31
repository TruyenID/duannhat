<?php

/**
 * PaymentRefund Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PaymentRefund\Models\PaymentRefundBaseModel;
use Database\Factories\PaymentRefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PaymentRefund — add project-specific model logic here.
 */
class PaymentRefund extends PaymentRefundBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PaymentRefundFactory
    {
        return PaymentRefundFactory::new();
    }

    //
}
