<?php

/**
 * ZoneTemplate Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ZoneTemplate\Models\ZoneTemplateBaseModel;
use Database\Factories\ZoneTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ZoneTemplate — add project-specific model logic here.
 */
class ZoneTemplate extends ZoneTemplateBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ZoneTemplateFactory
    {
        return ZoneTemplateFactory::new();
    }

    //
}
