<?php

/**
 * RecallAffectedOrder Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\RecallAffectedOrder\Models\RecallAffectedOrderBaseModel;
use Database\Factories\RecallAffectedOrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * RecallAffectedOrder — add project-specific model logic here.
 */
class RecallAffectedOrder extends RecallAffectedOrderBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): RecallAffectedOrderFactory
    {
        return RecallAffectedOrderFactory::new();
    }

    //
}
