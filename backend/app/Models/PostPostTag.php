<?php

/**
 * PostPostTag Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PostPostTag\Models\PostPostTagBaseModel;
use Database\Factories\PostPostTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PostPostTag — add project-specific model logic here.
 */
class PostPostTag extends PostPostTagBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PostPostTagFactory
    {
        return PostPostTagFactory::new();
    }

    //
}
