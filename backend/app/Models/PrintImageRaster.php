<?php

/**
 * PrintImageRaster Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\PrintImageRaster\Models\PrintImageRasterBaseModel;
use Database\Factories\PrintImageRasterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PrintImageRaster — add project-specific model logic here.
 */
class PrintImageRaster extends PrintImageRasterBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): PrintImageRasterFactory
    {
        return PrintImageRasterFactory::new();
    }

    //
}
