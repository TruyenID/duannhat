<?php

/**
 * MenuPromotionProduct Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuPromotionProduct\Models\MenuPromotionProductBaseModel;
use Database\Factories\MenuPromotionProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MenuPromotionProduct — add project-specific model logic here.
 */
class MenuPromotionProduct extends MenuPromotionProductBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MenuPromotionProductFactory
    {
        return MenuPromotionProductFactory::new();
    }

    //
}
