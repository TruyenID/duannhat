<?php

/**
 * BranchScheduleOverride Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\BranchScheduleOverride\Models\BranchScheduleOverrideBaseModel;
use Database\Factories\BranchScheduleOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * BranchScheduleOverride — add project-specific model logic here.
 */
class BranchScheduleOverride extends BranchScheduleOverrideBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): BranchScheduleOverrideFactory
    {
        return BranchScheduleOverrideFactory::new();
    }

    //
}
