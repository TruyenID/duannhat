<?php

/**
 * CustomerPointEntry Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\CustomerPointEntry\Models\CustomerPointEntryBaseModel;
use Database\Factories\CustomerPointEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * CustomerPointEntry — add project-specific model logic here.
 */
class CustomerPointEntry extends CustomerPointEntryBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CustomerPointEntryFactory
    {
        return CustomerPointEntryFactory::new();
    }

    //
}
