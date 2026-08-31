<?php

/**
 * PrintImageAsset Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PrintImageAsset\Models\PrintImageAssetBaseModel;
use Database\Factories\PrintImageAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PrintImageAsset — add project-specific model logic here.
 */
class PrintImageAsset extends PrintImageAssetBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PrintImageAssetFactory
    {
        return PrintImageAssetFactory::new();
    }

    //
}
