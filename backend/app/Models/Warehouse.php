<?php

/**
 * Warehouse Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Warehouse\Models\WarehouseBaseModel;
use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Warehouse — add project-specific model logic here.
 */
class Warehouse extends WarehouseBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): WarehouseFactory
    {
        return WarehouseFactory::new();
    }

    //
}
