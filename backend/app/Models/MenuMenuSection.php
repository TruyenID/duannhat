<?php

/**
 * MenuMenuSection Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Models\Concerns\RefusesKeylessWrites;
use App\Omnify\Modules\MenuMenuSection\Models\MenuMenuSectionBaseModel;
use Database\Factories\MenuMenuSectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MenuMenuSection — add project-specific model logic here.
 */
class MenuMenuSection extends MenuMenuSectionBaseModel
{
    use HasFactory;
    use RefusesKeylessWrites;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MenuMenuSectionFactory
    {
        return MenuMenuSectionFactory::new();
    }

    //
}
