<?php

/**
 * MaterialLotReservation Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MaterialLotReservation\Models\MaterialLotReservationBaseModel;
use Database\Factories\MaterialLotReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MaterialLotReservation — add project-specific model logic here.
 */
class MaterialLotReservation extends MaterialLotReservationBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MaterialLotReservationFactory
    {
        return MaterialLotReservationFactory::new();
    }

    //
}
