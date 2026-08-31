<?php

/**
 * StockTransfer Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\StockTransfer\Models\StockTransferBaseModel;
use Database\Factories\StockTransferFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * StockTransfer — add project-specific model logic here.
 */
class StockTransfer extends StockTransferBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): StockTransferFactory
    {
        return StockTransferFactory::new();
    }

    //
}
