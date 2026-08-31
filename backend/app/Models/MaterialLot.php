<?php

/**
 * MaterialLot Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MaterialLot\Models\MaterialLotBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\MaterialLotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MaterialLot — add project-specific model logic here.
 */
class MaterialLot extends MaterialLotBaseModel
{
    use AuditsActivity;
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MaterialLotFactory
    {
        return MaterialLotFactory::new();
    }

    //
}
