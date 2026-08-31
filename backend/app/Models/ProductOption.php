<?php

/**
 * ProductOption Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ProductOption\Models\ProductOptionBaseModel;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\ProductOptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ProductOption — add project-specific model logic here.
 */
class ProductOption extends ProductOptionBaseModel
{
    use HasFactory;
    use PreservesTranslatableColumns;

    protected $useTranslationFallback = true;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ProductOptionFactory
    {
        return ProductOptionFactory::new();
    }

    /**
     * Override the base relation so values are always returned in the
     * user-controlled order. Without this, eager loads (`with('values')`)
     * use database insertion order and the FE has to re-sort everywhere.
     */
    public function values(): HasMany
    {
        return parent::values()->orderBy('position');
    }
}
