<?php

/**
 * RecallDrill Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\RecallDrill\Models\RecallDrillBaseModel;
use Database\Factories\RecallDrillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * RecallDrill — add project-specific model logic here.
 */
class RecallDrill extends RecallDrillBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): RecallDrillFactory
    {
        return RecallDrillFactory::new();
    }

    //
}
