<?php

/**
 * StockTransferItem Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\StockTransferItem\Models\StockTransferItemBaseModel;
use Database\Factories\StockTransferItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * StockTransferItem — add project-specific model logic here.
 */
class StockTransferItem extends StockTransferItemBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): StockTransferItemFactory
    {
        return StockTransferItemFactory::new();
    }

    //
}
