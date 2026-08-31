<?php

/**
 * StockCount Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\StockCount\Models\StockCountBaseModel;
use Database\Factories\StockCountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * StockCount — add project-specific model logic here.
 */
class StockCount extends StockCountBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): StockCountFactory
    {
        return StockCountFactory::new();
    }

    //
}
