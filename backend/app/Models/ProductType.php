<?php

/**
 * ProductType Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ProductType\Models\ProductTypeBaseModel;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\ProductTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ProductType — add project-specific model logic here.
 */
class ProductType extends ProductTypeBaseModel
{
    use HasFactory;
    use PreservesTranslatableColumns;

    /**
     * Enable Astrotomic fallback: missing translation → fallback_locale (en)
     * → base column (`product_types.name` / `product_types.description`).
     * PreservesTranslatableColumns populates the base column from ja → en →
     * vi priority on write, so the last-resort property fallback always
     * resolves to a sensible non-null value even if the user entered only
     * one language.
     *
     * Pair with config('translatable.use_property_fallback') = true.
     */
    protected $useTranslationFallback = true;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ProductTypeFactory
    {
        return ProductTypeFactory::new();
    }
}
