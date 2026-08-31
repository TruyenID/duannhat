<?php

/**
 * RolePermission Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\RolePermission\Models\RolePermissionBaseModel;
use Database\Factories\RolePermissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * RolePermission — add project-specific model logic here.
 */
class RolePermission extends RolePermissionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): RolePermissionFactory
    {
        return RolePermissionFactory::new();
    }

    //
}
