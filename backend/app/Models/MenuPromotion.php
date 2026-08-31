<?php

/**
 * MenuPromotion Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuPromotion\Models\MenuPromotionBaseModel;
use App\Traits\AuditsActivity;
use Database\Factories\MenuPromotionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * MenuPromotion — add project-specific model logic here.
 */
class MenuPromotion extends MenuPromotionBaseModel
{
    use AuditsActivity;
    use HasFactory;

    protected $casts = [
        'discount_percent' => 'decimal:2',
        'daily_time_from' => 'string',
        'daily_time_to' => 'string',
        'weekdays' => 'array',
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
    ];

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MenuPromotionFactory
    {
        return MenuPromotionFactory::new();
    }

    /**
     * Localised name/description rows. Read-side only — workstation
     * replica feed surfaces these so LAN-mode tills can display the
     * cashier's locale even when offline.
     */
    public function translations(): HasMany
    {
        return $this->hasMany(MenuPromotionTranslation::class, 'menu_promotion_id');
    }
}
