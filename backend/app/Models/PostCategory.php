<?php

/**
 * PostCategory Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PostCategory\Models\PostCategoryBaseModel;
use Database\Factories\PostCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PostCategory — add project-specific model logic here.
 */
class PostCategory extends PostCategoryBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PostCategoryFactory
    {
        return PostCategoryFactory::new();
    }

    //
}
