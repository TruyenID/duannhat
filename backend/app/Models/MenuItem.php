<?php

/**
 * MenuItem Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuItem\Models\MenuItemBaseModel;
use Database\Factories\MenuItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MenuItem — add project-specific model logic here.
 */
class MenuItem extends MenuItemBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MenuItemFactory
    {
        return MenuItemFactory::new();
    }

    //
}
