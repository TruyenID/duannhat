<?php

/**
 * NotificationDigestPreference Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationDigestPreference\Models\NotificationDigestPreferenceBaseModel;
use Database\Factories\NotificationDigestPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationDigestPreference — add project-specific model logic here.
 */
class NotificationDigestPreference extends NotificationDigestPreferenceBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationDigestPreferenceFactory
    {
        return NotificationDigestPreferenceFactory::new();
    }

    //
}
