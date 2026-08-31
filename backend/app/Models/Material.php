<?php

/**
 * Material Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Enums\MaterialLotStatusEnum;
use App\Omnify\Modules\Material\Models\MaterialBaseModel;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Material — add project-specific model logic here.
 */
class Material extends MaterialBaseModel
{
    use HasFactory;

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory(): MaterialFactory
    {
        return MaterialFactory::new();
    }

    /** Plan-017 — every MaterialLot for this material across all warehouses. */
    public function lots(): HasMany
    {
        return $this->hasMany(MaterialLot::class);
    }

    /** Plan-017 MOD-1 — active lots only (FEFO-eligible). */
    public function activeLots(): HasMany
    {
        return $this->hasMany(MaterialLot::class)
            ->where('status', MaterialLotStatusEnum::Active->value)
            ->where('qty_on_hand', '>', 0);
    }

    /**
     * Plan-017 MOD-1 — active lots whose expiry_date is within 7 days.
     *
     * #1091 NOTE: a relation has no branch context, and one material's lots can
     * sit in several branches with different timezones, so there is no single
     * right "today" here. This is a COARSE UI hint only — the authoritative,
     * per-branch expiry decisions live in ExpiryAlertService and
     * AutoExpireMaterialLots, which resolve each lot's own branch timezone.
     */
    public function expiringSoonLots(): HasMany
    {
        return $this->hasMany(MaterialLot::class)
            ->where('status', MaterialLotStatusEnum::Active->value)
            ->where('qty_on_hand', '>', 0)
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [
                // Widened by a day at each end so no branch timezone can fall
                // outside this coarse window. #1091-ok: coarse UI hint, see above.
                now()->subDay()->toDateString(),
                now()->addDays(8)->toDateString(),
            ]);
    }
}
