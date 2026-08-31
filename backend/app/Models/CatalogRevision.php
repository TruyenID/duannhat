<?php

/**
 * CatalogRevision Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\CatalogRevision\Models\CatalogRevisionBaseModel;
use Database\Factories\CatalogRevisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * CatalogRevision — add project-specific model logic here.
 */
class CatalogRevision extends CatalogRevisionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CatalogRevisionFactory
    {
        return CatalogRevisionFactory::new();
    }

    //
}
