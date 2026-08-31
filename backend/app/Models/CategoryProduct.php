<?php

/**
 * CategoryProduct Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\CategoryProduct\Models\CategoryProductBaseModel;
use Database\Factories\CategoryProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * CategoryProduct — add project-specific model logic here.
 */
class CategoryProduct extends CategoryProductBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CategoryProductFactory
    {
        return CategoryProductFactory::new();
    }

    //
}
