<?php

/**
 * TaxType Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\TaxType\Models\TaxTypeBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\TaxTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * TaxType — add project-specific model logic here.
 */
class TaxType extends TaxTypeBaseModel
{
    // plan-043 T1.15 — tax config is compliance data; audit every CRUD.
    use AuditsActivity;
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TaxTypeFactory
    {
        return TaxTypeFactory::new();
    }

    //
}
