<?php

/**
 * AllergenMaterial Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\AllergenMaterial\Models\AllergenMaterialBaseModel;
use Database\Factories\AllergenMaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * AllergenMaterial — add project-specific model logic here.
 */
class AllergenMaterial extends AllergenMaterialBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): AllergenMaterialFactory
    {
        return AllergenMaterialFactory::new();
    }

    //
}
