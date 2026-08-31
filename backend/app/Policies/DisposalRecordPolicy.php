<?php

namespace App\Policies;

use App\Models\DisposalRecord;
use App\Models\User;
use App\Policies\Traits\ResolvesOrganization;

class DisposalRecordPolicy
{
    use ResolvesOrganization;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, DisposalRecord $disposalRecord): bool
    {
        $disposalRecord->loadMissing('stockTransactionItem.stockTransaction');
        $tx = $disposalRecord->stockTransactionItem?->stockTransaction;

        return $tx !== null && $this->belongsToUserOrg($user, $tx);
    }
}
