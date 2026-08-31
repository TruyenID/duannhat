<?php

/**
 * CashDeviceErrorEvent Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\CashDeviceErrorEvent\Models\CashDeviceErrorEventBaseModel;
use Database\Factories\CashDeviceErrorEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * CashDeviceErrorEvent — add project-specific model logic here.
 */
class CashDeviceErrorEvent extends CashDeviceErrorEventBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CashDeviceErrorEventFactory
    {
        return CashDeviceErrorEventFactory::new();
    }

    //
}
