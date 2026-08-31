<?php

/**
 * ProductionOrderItem Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ProductionOrderItem\Models\ProductionOrderItemBaseModel;
use Database\Factories\ProductionOrderItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ProductionOrderItem — add project-specific model logic here.
 */
class ProductionOrderItem extends ProductionOrderItemBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ProductionOrderItemFactory
    {
        return ProductionOrderItemFactory::new();
    }

    //
}
