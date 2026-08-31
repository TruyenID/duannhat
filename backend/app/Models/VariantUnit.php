<?php

/**
 * VariantUnit Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\VariantUnit\Models\VariantUnitBaseModel;
use Database\Factories\VariantUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * VariantUnit — add project-specific model logic here.
 */
class VariantUnit extends VariantUnitBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): VariantUnitFactory
    {
        return VariantUnitFactory::new();
    }

    //
}
