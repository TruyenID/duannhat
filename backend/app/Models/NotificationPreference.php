<?php

/**
 * NotificationPreference Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationPreference\Models\NotificationPreferenceBaseModel;
use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationPreference — add project-specific model logic here.
 */
class NotificationPreference extends NotificationPreferenceBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationPreferenceFactory
    {
        return NotificationPreferenceFactory::new();
    }

    //
}
