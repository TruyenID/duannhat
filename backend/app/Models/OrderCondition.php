<?php

/**
 * OrderCondition Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\OrderCondition\Models\OrderConditionBaseModel;
use Database\Factories\OrderConditionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * OrderCondition — add project-specific model logic here.
 */
class OrderCondition extends OrderConditionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): OrderConditionFactory
    {
        return OrderConditionFactory::new();
    }

    //
}
