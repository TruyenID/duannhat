<?php

/**
 * ToppingGroupItemSku Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\ToppingGroupItemSku\Models\ToppingGroupItemSkuBaseModel;
use Database\Factories\ToppingGroupItemSkuFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * ToppingGroupItemSku — add project-specific model logic here.
 */
class ToppingGroupItemSku extends ToppingGroupItemSkuBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): ToppingGroupItemSkuFactory
    {
        return ToppingGroupItemSkuFactory::new();
    }

    //
}
