<?php

/**
 * CashDeviceTransaction Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\CashDeviceTransaction\Models\CashDeviceTransactionBaseModel;
use Database\Factories\CashDeviceTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * CashDeviceTransaction — add project-specific model logic here.
 */
class CashDeviceTransaction extends CashDeviceTransactionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CashDeviceTransactionFactory
    {
        return CashDeviceTransactionFactory::new();
    }

    //
}
