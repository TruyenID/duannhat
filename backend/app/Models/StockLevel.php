<?php

/**
 * StockLevel Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\StockLevel\Models\StockLevelBaseModel;
use Database\Factories\StockLevelFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * StockLevel — add project-specific model logic here.
 */
class StockLevel extends StockLevelBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): StockLevelFactory
    {
        return StockLevelFactory::new();
    }

    //
}
