<?php

/**
 * MaterialBatchItem Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MaterialBatchItem\Models\MaterialBatchItemBaseModel;
use Database\Factories\MaterialBatchItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MaterialBatchItem — add project-specific model logic here.
 */
class MaterialBatchItem extends MaterialBatchItemBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MaterialBatchItemFactory
    {
        return MaterialBatchItemFactory::new();
    }

    //
}
