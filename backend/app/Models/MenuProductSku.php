<?php

/**
 * MenuProductSku Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuProductSku\Models\MenuProductSkuBaseModel;
use Database\Factories\MenuProductSkuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MenuProductSku — add project-specific model logic here.
 */
class MenuProductSku extends MenuProductSkuBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MenuProductSkuFactory
    {
        return MenuProductSkuFactory::new();
    }

    //
}
