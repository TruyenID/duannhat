<?php

/**
 * DevicePaymentOption Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\DevicePaymentOption\Models\DevicePaymentOptionBaseModel;
use Database\Factories\DevicePaymentOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * DevicePaymentOption — add project-specific model logic here.
 */
class DevicePaymentOption extends DevicePaymentOptionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): DevicePaymentOptionFactory
    {
        return DevicePaymentOptionFactory::new();
    }

    //
}
