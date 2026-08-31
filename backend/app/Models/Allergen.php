<?php

/**
 * Allergen Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Allergen\Models\AllergenBaseModel;
use Database\Factories\AllergenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Allergen — add project-specific model logic here.
 */
class Allergen extends AllergenBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): AllergenFactory
    {
        return AllergenFactory::new();
    }
}
