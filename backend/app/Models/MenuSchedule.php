<?php

/**
 * MenuSchedule Model
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Models;

use App\Omnify\Modules\MenuSchedule\Models\MenuScheduleBaseModel;
use App\Services\Product\MenuScheduleService;
use Database\Factories\MenuScheduleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * MenuSchedule — add project-specific model logic here.
 *
 * branchScheduleOverrides() HasMany is provided by MenuScheduleBaseModel (omnify-generated).
 */
class MenuSchedule extends MenuScheduleBaseModel
{
    use HasFactory;

    protected static function newFactory(): MenuScheduleFactory
    {
        return MenuScheduleFactory::new();
    }

    /**
     * Cascade-delete branch overrides on hard-delete.
     */
    protected static function booted(): void
    {
        static::forceDeleted(function (MenuSchedule $schedule): void {
            app(MenuScheduleService::class)->cascadeDeleteBranchOverrides($schedule->id);
        });
    }
}
