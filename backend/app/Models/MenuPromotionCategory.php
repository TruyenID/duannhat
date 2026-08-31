<?php

/**
 * MenuPromotionCategory Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuPromotionCategory\Models\MenuPromotionCategoryBaseModel;
use Database\Factories\MenuPromotionCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MenuPromotionCategory — add project-specific model logic here.
 */
class MenuPromotionCategory extends MenuPromotionCategoryBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MenuPromotionCategoryFactory
    {
        return MenuPromotionCategoryFactory::new();
    }

    //
}
