<?php

/**
 * Denomination Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Denomination\Models\DenominationBaseModel;
use Database\Factories\DenominationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Denomination — add project-specific model logic here.
 */
class Denomination extends DenominationBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): DenominationFactory
    {
        return DenominationFactory::new();
    }

    //
}
