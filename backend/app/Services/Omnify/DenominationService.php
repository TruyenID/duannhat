<?php

/**
 * Denomination Service
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Omnify;

use App\Models\Denomination;
use App\Models\Till;
use App\Omnify\Modules\Denomination\Services\DenominationServiceBase;

/**
 * Plan 030 — wrapper for the omnify-generated DenominationServiceBase.
 * Used by DenominationSeeder to keep create/update routed through the
 * service layer (convention #3 — no raw Model::create on Omnify-managed
 * tables).
 */
class DenominationService extends DenominationServiceBase
{
    /**
     * plan-042: block deleting a denomination while any till in its
     * organization has an open shift — a mid-shift denomination change would
     * corrupt the per-shift cash reconciliation (same rationale as plan-031's
     * currency-change guard).
     */
    public function delete(Denomination $model): bool
    {
        $hasOpenShift = Till::where('organization_id', $model->organization_id)
            ->whereNotNull('current_session_id')
            ->exists();

        if ($hasOpenShift) {
            abort(response()->json([
                'message' => 'Cannot delete a denomination while a cashier shift is open. Close all open shifts first.',
                'code' => 'DENOMINATION_DELETE_BLOCKED_OPEN_SHIFT',
            ], 409));
        }

        return parent::delete($model);
    }
}
