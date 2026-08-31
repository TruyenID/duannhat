<?php

/**
 * CouponRedemption Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\CouponRedemption\Models\CouponRedemptionBaseModel;
use Database\Factories\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * CouponRedemption — add project-specific model logic here.
 */
class CouponRedemption extends CouponRedemptionBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CouponRedemptionFactory
    {
        return CouponRedemptionFactory::new();
    }

    //
}
