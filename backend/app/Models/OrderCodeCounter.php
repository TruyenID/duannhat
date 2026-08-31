<?php

/**
 * OrderCodeCounter Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\OrderCodeCounter\Models\OrderCodeCounterBaseModel;
use Database\Factories\OrderCodeCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * OrderCodeCounter — add project-specific model logic here.
 */
class OrderCodeCounter extends OrderCodeCounterBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): OrderCodeCounterFactory
    {
        return OrderCodeCounterFactory::new();
    }

    //
}
