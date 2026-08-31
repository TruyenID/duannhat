<?php

/**
 * PostTag Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PostTag\Models\PostTagBaseModel;
use Database\Factories\PostTagFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PostTag — add project-specific model logic here.
 */
class PostTag extends PostTagBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PostTagFactory
    {
        return PostTagFactory::new();
    }

    //
}
