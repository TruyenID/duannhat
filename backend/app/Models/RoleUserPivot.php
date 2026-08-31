<?php

/**
 * RoleUserPivot Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\RoleUserPivot\Models\RoleUserPivotBaseModel;
use Database\Factories\RoleUserPivotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * RoleUserPivot — add project-specific model logic here.
 */
class RoleUserPivot extends RoleUserPivotBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): RoleUserPivotFactory
    {
        return RoleUserPivotFactory::new();
    }

    //
}
