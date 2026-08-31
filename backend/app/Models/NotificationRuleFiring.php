<?php

/**
 * NotificationRuleFiring Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationRuleFiring\Models\NotificationRuleFiringBaseModel;
use Database\Factories\NotificationRuleFiringFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationRuleFiring — add project-specific model logic here.
 */
class NotificationRuleFiring extends NotificationRuleFiringBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationRuleFiringFactory
    {
        return NotificationRuleFiringFactory::new();
    }

    //
}
