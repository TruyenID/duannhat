<?php

/**
 * BranchMenuSchedulePolicy
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 *
 * Governs branch-level access to schedule override operations.
 * Registered via Gate::define in AppServiceProvider (ability names:
 * 'branch-schedule.upsert', 'branch-schedule.reset').
 *
 * HQ-level gates (view/update/delete on the schedule itself) live in
 * MenuSchedulePolicy, which is auto-discovered for the MenuSchedule model.
 */

namespace App\Policies;

use App\Models\Branch;
use App\Models\MenuSchedule;
use App\Models\User;
use App\Policies\Traits\ChecksShopContext;

class BranchMenuSchedulePolicy
{
    use ChecksShopContext;

    /**
     * Branch manager can upsert a time override on this schedule for their branch.
     *
     * Requires the user to be a shop-manager (or org-manager / org-admin) for the
     * specific branch, resolved via the organization context set by
     * ResolveBranchFromSlug middleware.
     */
    public function upsert(User $user, MenuSchedule $schedule, Branch $branch): bool
    {
        // isShopManager reads organization_id from request()->attributes (set by
        // ResolveBranchFromSlug middleware). The console_organization_id check
        // ensures the user belongs to the same org as the branch (org boundary).
        return $this->isShopManager($user, $branch->id)
            && $user->console_organization_id === $branch->console_organization_id;
    }

    /**
     * Branch manager can reset (delete) their override — same gate as upsert.
     */
    public function reset(User $user, MenuSchedule $schedule, Branch $branch): bool
    {
        return $this->upsert($user, $schedule, $branch);
    }
}
