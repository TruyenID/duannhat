<?php

/**
 * NotificationChannelRoute Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationChannelRoute\Models\NotificationChannelRouteBaseModel;
use Database\Factories\NotificationChannelRouteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationChannelRoute — add project-specific model logic here.
 */
class NotificationChannelRoute extends NotificationChannelRouteBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationChannelRouteFactory
    {
        return NotificationChannelRouteFactory::new();
    }

    //
}
