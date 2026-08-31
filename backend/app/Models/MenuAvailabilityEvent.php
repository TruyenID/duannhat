<?php

/**
 * MenuAvailabilityEvent Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuAvailabilityEvent\Models\MenuAvailabilityEventBaseModel;
use Database\Factories\MenuAvailabilityEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MenuAvailabilityEvent — add project-specific model logic here.
 */
class MenuAvailabilityEvent extends MenuAvailabilityEventBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MenuAvailabilityEventFactory
    {
        return MenuAvailabilityEventFactory::new();
    }

    //
}
