<?php

/**
 * MenuSchedulePolicy
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Policies;

use App\Models\MenuSchedule;
use App\Models\User;

class MenuSchedulePolicy
{
    /**
     * Viewing a schedule requires being able to view the parent menu.
     */
    public function view(User $user, MenuSchedule $schedule): bool
    {
        return $user->can('view', $schedule->menu);
    }

    /**
     * Updating a schedule requires update-level access on the parent menu.
     * Delegates to MenuPolicy@update which enforces org-boundary check.
     */
    public function update(User $user, MenuSchedule $schedule): bool
    {
        return $user->can('update', $schedule->menu);
    }

    /**
     * Deleting a schedule requires update-level access on the parent menu
     * (schedule deletion is an update action on the menu's config).
     */
    public function delete(User $user, MenuSchedule $schedule): bool
    {
        return $user->can('update', $schedule->menu);
    }
}
