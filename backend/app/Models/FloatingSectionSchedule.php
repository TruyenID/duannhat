<?php

/**
 * FloatingSectionSchedule Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\FloatingSectionSchedule\Models\FloatingSectionScheduleBaseModel;
use Database\Factories\FloatingSectionScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * FloatingSectionSchedule — add project-specific model logic here.
 */
class FloatingSectionSchedule extends FloatingSectionScheduleBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): FloatingSectionScheduleFactory
    {
        return FloatingSectionScheduleFactory::new();
    }

    //
}
