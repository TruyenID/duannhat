<?php

/**
 * NotificationAudience Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationAudience\Models\NotificationAudienceBaseModel;
use Database\Factories\NotificationAudienceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationAudience — add project-specific model logic here.
 */
class NotificationAudience extends NotificationAudienceBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationAudienceFactory
    {
        return NotificationAudienceFactory::new();
    }

    //
}
