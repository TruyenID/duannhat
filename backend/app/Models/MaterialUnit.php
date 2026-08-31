<?php

/**
 * MaterialUnit Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MaterialUnit\Models\MaterialUnitBaseModel;
use Database\Factories\MaterialUnitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MaterialUnit — add project-specific model logic here.
 */
class MaterialUnit extends MaterialUnitBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MaterialUnitFactory
    {
        return MaterialUnitFactory::new();
    }

    //
}
