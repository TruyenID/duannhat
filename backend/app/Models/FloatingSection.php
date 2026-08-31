<?php

/**
 * FloatingSection Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\FloatingSection\Models\FloatingSectionBaseModel;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\FloatingSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * FloatingSection — add project-specific model logic here.
 */
class FloatingSection extends FloatingSectionBaseModel
{
    use HasFactory;

    // Keeps the base `floating_sections.name` column populated + serialized
    // even though `name` is an Astrotomic translated attribute — same standard
    // as Product/Menu. Astrotomic otherwise strips `name` on fill(), leaving
    // the mirror NULL. See the trait's docblock for the full read/write story.
    use PreservesTranslatableColumns;

    /**
     * Enable Astrotomic fallback: missing translation → fallback_locale →
     * base column. Pair with config('translatable.use_property_fallback').
     */
    protected $useTranslationFallback = true;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): FloatingSectionFactory
    {
        return FloatingSectionFactory::new();
    }

    //
}
