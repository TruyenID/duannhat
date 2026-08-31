<?php

/**
 * CashDeviceInventorySnapshot Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\CashDeviceInventorySnapshot\Models\CashDeviceInventorySnapshotBaseModel;
use Database\Factories\CashDeviceInventorySnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * CashDeviceInventorySnapshot — add project-specific model logic here.
 */
class CashDeviceInventorySnapshot extends CashDeviceInventorySnapshotBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CashDeviceInventorySnapshotFactory
    {
        return CashDeviceInventorySnapshotFactory::new();
    }

    //
}
