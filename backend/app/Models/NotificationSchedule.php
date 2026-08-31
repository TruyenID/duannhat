<?php

/**
 * NotificationSchedule Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationSchedule\Models\NotificationScheduleBaseModel;
use Database\Factories\NotificationScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationSchedule — add project-specific model logic here.
 */
class NotificationSchedule extends NotificationScheduleBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationScheduleFactory
    {
        return NotificationScheduleFactory::new();
    }

    //
}
