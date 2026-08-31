<?php

/**
 * Coupon Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\Coupon\Models\CouponBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Coupon — add project-specific model logic here.
 */
class Coupon extends CouponBaseModel
{
    use AuditsActivity;
    use HasFactory;

    protected $casts = [
        'discount_value' => 'decimal:2',
        'max_discount_cap' => 'decimal:2',
        'min_order_subtotal' => 'decimal:2',
        'usage_limit_total' => 'integer',
        'usage_limit_per_customer' => 'integer',
        'times_used' => 'integer',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): CouponFactory
    {
        return CouponFactory::new();
    }

    /**
     * Localised name/description rows. Read-side only — workstation
     * replica feed surfaces these so LAN-mode tills can display the
     * cashier's locale even when offline.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CouponTranslation::class, 'coupon_id');
    }
}
