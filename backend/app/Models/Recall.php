<?php

/**
 * Recall Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Recall\Models\RecallBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\RecallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Recall — add project-specific model logic here.
 */
class Recall extends RecallBaseModel
{
    use AuditsActivity;
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): RecallFactory
    {
        return RecallFactory::new();
    }

    //
}
