<?php

/**
 * BranchFloatingSectionOverride Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\BranchFloatingSectionOverride\Models\BranchFloatingSectionOverrideBaseModel;
use Database\Factories\BranchFloatingSectionOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * BranchFloatingSectionOverride — add project-specific model logic here.
 */
class BranchFloatingSectionOverride extends BranchFloatingSectionOverrideBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): BranchFloatingSectionOverrideFactory
    {
        return BranchFloatingSectionOverrideFactory::new();
    }

    //
}
