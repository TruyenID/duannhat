<?php

/**
 * Category Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Category\Models\CategoryBaseModel;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Category — add project-specific model logic here.
 */
class Category extends CategoryBaseModel
{
    use HasFactory;
    use PreservesTranslatableColumns;

    /**
     * Add is_featured to fillable attributes.
     */
    protected $fillable = [
        'sku',
        'name',
        'slug',
        'description',
        'image_url',
        'is_active',
        'is_featured',
        'parent_id',
        'organization_id',
        'brand_id',
    ];

    /**
     * Enable Astrotomic fallback for this model.
     *
     * When a translation row is missing (or empty) for the requested
     * locale, Astrotomic walks: fallback_locale translation → base column
     * (`categories.name` / `categories.description`). CategoryService
     * populates the base column from the ja → en → vi priority on write,
     * so the last-resort property fallback always resolves to a sensible
     * non-null value even if the user entered only one language.
     *
     * Pair with config('translatable.use_property_fallback') = true.
     */
    protected $useTranslationFallback = true;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CategoryFactory
    {
        return CategoryFactory::new();
    }

    //
}
