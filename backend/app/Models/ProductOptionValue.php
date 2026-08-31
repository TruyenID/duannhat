<?php

/**
 * ProductOptionValue Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ProductOptionValue\Models\ProductOptionValueBaseModel;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\ProductOptionValueFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ProductOptionValue — add project-specific model logic here.
 */
class ProductOptionValue extends ProductOptionValueBaseModel
{
    use HasFactory;
    use PreservesTranslatableColumns;

    protected $useTranslationFallback = true;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ProductOptionValueFactory
    {
        return ProductOptionValueFactory::new();
    }
}
