<?php

/**
 * TableStatusChange Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\TableStatusChange\Models\TableStatusChangeBaseModel;
use Database\Factories\TableStatusChangeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * TableStatusChange — add project-specific model logic here.
 */
class TableStatusChange extends TableStatusChangeBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): TableStatusChangeFactory
    {
        return TableStatusChangeFactory::new();
    }

    //
}
