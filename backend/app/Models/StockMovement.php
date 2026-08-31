<?php

/**
 * StockMovement Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\StockMovement\Models\StockMovementBaseModel;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * StockMovement — add project-specific model logic here.
 */
class StockMovement extends StockMovementBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): StockMovementFactory
    {
        return StockMovementFactory::new();
    }

    //
}
