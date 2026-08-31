<?php

/**
 * MaterialSubstitutionRule Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MaterialSubstitutionRule\Models\MaterialSubstitutionRuleBaseModel;
use Database\Factories\MaterialSubstitutionRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MaterialSubstitutionRule — add project-specific model logic here.
 */
class MaterialSubstitutionRule extends MaterialSubstitutionRuleBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MaterialSubstitutionRuleFactory
    {
        return MaterialSubstitutionRuleFactory::new();
    }

    //
}
