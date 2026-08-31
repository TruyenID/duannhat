<?php

/**
 * TillTenderType Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\TillTenderType\Models\TillTenderTypeBaseModel;
use Database\Factories\TillTenderTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * TillTenderType — add project-specific model logic here.
 */
class TillTenderType extends TillTenderTypeBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TillTenderTypeFactory
    {
        return TillTenderTypeFactory::new();
    }

}
