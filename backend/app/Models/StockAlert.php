<?php

/**
 * StockAlert Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\StockAlert\Models\StockAlertBaseModel;
use Database\Factories\StockAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * StockAlert — add project-specific model logic here.
 */
class StockAlert extends StockAlertBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): StockAlertFactory
    {
        return StockAlertFactory::new();
    }

    //
}
