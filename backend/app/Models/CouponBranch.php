<?php

/**
 * CouponBranch Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\CouponBranch\Models\CouponBranchBaseModel;
use Database\Factories\CouponBranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * CouponBranch — add project-specific model logic here.
 */
class CouponBranch extends CouponBranchBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CouponBranchFactory
    {
        return CouponBranchFactory::new();
    }

    //
}
