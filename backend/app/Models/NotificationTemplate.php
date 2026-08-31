<?php

/**
 * NotificationTemplate Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationTemplate\Models\NotificationTemplateBaseModel;
use Database\Factories\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationTemplate — add project-specific model logic here.
 */
class NotificationTemplate extends NotificationTemplateBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationTemplateFactory
    {
        return NotificationTemplateFactory::new();
    }

    //
}
