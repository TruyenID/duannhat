<?php

/**
 * TillTenderType Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Omnify\Modules\TillTenderType\Services\TillTenderTypeServiceBase;

/**
 * Plan 030 — wrapper for the omnify-generated TillTenderTypeServiceBase.
 * Used by TillTenderTypeSeeder to flush translations via the service path
 * (convention #3).
 */
class TillTenderTypeService extends TillTenderTypeServiceBase
{
    /**
     * Eager-load translations so seeder updates roundtrip the ja/en/vi keys.
     */
    protected function applyListEagerLoads($query): void
    {
        $query->with('translations');
    }

    protected function applyFindByIdEagerLoads($query): void
    {
        $query->with('translations');
    }
}
