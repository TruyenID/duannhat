<?php

/**
 * ProductionOrder Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ProductionOrder\Models\ProductionOrderBaseModel;
use Database\Factories\ProductionOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ProductionOrder — add project-specific model logic here.
 */
class ProductionOrder extends ProductionOrderBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ProductionOrderFactory
    {
        return ProductionOrderFactory::new();
    }

    //
}
