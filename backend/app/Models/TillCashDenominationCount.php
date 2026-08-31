<?php

/**
 * TillCashDenominationCount Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\TillCashDenominationCount\Models\TillCashDenominationCountBaseModel;
use Database\Factories\TillCashDenominationCountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * TillCashDenominationCount — add project-specific model logic here.
 */
class TillCashDenominationCount extends TillCashDenominationCountBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TillCashDenominationCountFactory
    {
        return TillCashDenominationCountFactory::new();
    }

    //
}
