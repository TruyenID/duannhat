<?php

/**
 * MenuScheduleDate Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuScheduleDate\Models\MenuScheduleDateBaseModel;
use Database\Factories\MenuScheduleDateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MenuScheduleDate — add project-specific model logic here.
 */
class MenuScheduleDate extends MenuScheduleDateBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MenuScheduleDateFactory
    {
        return MenuScheduleDateFactory::new();
    }

    //
}
