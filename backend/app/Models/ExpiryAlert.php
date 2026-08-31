<?php

/**
 * ExpiryAlert Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ExpiryAlert\Models\ExpiryAlertBaseModel;
use Database\Factories\ExpiryAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ExpiryAlert — add project-specific model logic here.
 */
class ExpiryAlert extends ExpiryAlertBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ExpiryAlertFactory
    {
        return ExpiryAlertFactory::new();
    }

    //
}
