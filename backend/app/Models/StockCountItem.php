<?php

/**
 * StockCountItem Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\StockCountItem\Models\StockCountItemBaseModel;
use Database\Factories\StockCountItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * StockCountItem — add project-specific model logic here.
 */
class StockCountItem extends StockCountItemBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): StockCountItemFactory
    {
        return StockCountItemFactory::new();
    }

    //
}
