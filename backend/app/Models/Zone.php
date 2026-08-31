<?php

/**
 * Zone Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Zone\Models\ZoneBaseModel;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Zone — add project-specific model logic here.
 */
class Zone extends ZoneBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ZoneFactory
    {
        return ZoneFactory::new();
    }

    //
}
