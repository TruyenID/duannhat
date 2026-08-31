<?php

/**
 * NotificationDelivery Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\NotificationDelivery\Models\NotificationDeliveryBaseModel;
use Database\Factories\NotificationDeliveryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * NotificationDelivery — add project-specific model logic here.
 */
class NotificationDelivery extends NotificationDeliveryBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): NotificationDeliveryFactory
    {
        return NotificationDeliveryFactory::new();
    }

    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'attempts' => 'integer',
        ];
    }
}
