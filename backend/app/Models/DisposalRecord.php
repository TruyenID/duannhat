<?php

/**
 * DisposalRecord Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\DisposalRecord\Models\DisposalRecordBaseModel;
use Database\Factories\DisposalRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * DisposalRecord — add project-specific model logic here.
 */
class DisposalRecord extends DisposalRecordBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): DisposalRecordFactory
    {
        return DisposalRecordFactory::new();
    }

    //
}
