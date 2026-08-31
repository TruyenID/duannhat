<?php

/**
 * WorkstationLogRequest Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\WorkstationLogRequest\Models\WorkstationLogRequestBaseModel;
use Database\Factories\WorkstationLogRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * WorkstationLogRequest — add project-specific model logic here.
 */
class WorkstationLogRequest extends WorkstationLogRequestBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): WorkstationLogRequestFactory
    {
        return WorkstationLogRequestFactory::new();
    }

    //
}
