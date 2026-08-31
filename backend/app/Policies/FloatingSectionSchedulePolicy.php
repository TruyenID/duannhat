<?php

/**
 * FloatingSectionSchedulePolicy
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Policies;

use App\Models\FloatingSectionSchedule;
use App\Models\User;

/**
 * Mirrors MenuSchedulePolicy — schedule access delegates to the parent
 * FloatingSection's own policy, so the org boundary is enforced in one place.
 */
class FloatingSectionSchedulePolicy
{
    public function view(User $user, FloatingSectionSchedule $schedule): bool
    {
        return $user->can('view', $schedule->floatingSection);
    }

    public function update(User $user, FloatingSectionSchedule $schedule): bool
    {
        return $user->can('update', $schedule->floatingSection);
    }

    public function delete(User $user, FloatingSectionSchedule $schedule): bool
    {
        return $user->can('update', $schedule->floatingSection);
    }
}
