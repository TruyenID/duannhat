<?php

/**
 * NotificationEmailSuppression Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationEmailSuppression\Models\NotificationEmailSuppressionBaseModel;
use Database\Factories\NotificationEmailSuppressionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationEmailSuppression — add project-specific model logic here.
 */
class NotificationEmailSuppression extends NotificationEmailSuppressionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationEmailSuppressionFactory
    {
        return NotificationEmailSuppressionFactory::new();
    }

    //
}
