<?php

/**
 * StockTransactionItem Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\StockTransactionItem\Models\StockTransactionItemBaseModel;
use Database\Factories\StockTransactionItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * StockTransactionItem — add project-specific model logic here.
 */
class StockTransactionItem extends StockTransactionItemBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): StockTransactionItemFactory
    {
        return StockTransactionItemFactory::new();
    }

    //
}
