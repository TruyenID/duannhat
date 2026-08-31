<?php

/**
 * TaxTypeRate Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\TaxTypeRate\Models\TaxTypeRateBaseModel;
use Database\Factories\TaxTypeRateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * TaxTypeRate — add project-specific model logic here.
 */
class TaxTypeRate extends TaxTypeRateBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TaxTypeRateFactory
    {
        return TaxTypeRateFactory::new();
    }

    //
}
