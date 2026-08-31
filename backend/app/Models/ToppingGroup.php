<?php

/**
 * ToppingGroup Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ToppingGroup\Models\ToppingGroupBaseModel;
use App\Traits\PreservesTranslatableColumns;
use Database\Factories\ToppingGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ToppingGroup — add project-specific model logic here.
 */
class ToppingGroup extends ToppingGroupBaseModel
{
    use HasFactory;
    use PreservesTranslatableColumns;

    protected $useTranslationFallback = true;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ToppingGroupFactory
    {
        return ToppingGroupFactory::new();
    }
}
