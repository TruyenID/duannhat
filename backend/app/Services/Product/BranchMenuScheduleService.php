<?php

namespace App\Services\Product;

use App\Models\Branch;
use App\Models\BranchScheduleOverride;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class BranchMenuScheduleService
{
    /**
     * Return active schedules for a menu with COALESCED effective times for the branch.
     *
     * SQL aliases (effective_start_time, effective_end_time, hq_start_time,
     * hq_end_time, is_overridden) are hydrated as dynamic properties on the model.
     * VERIFY these keys exist via tinker before writing BranchEffectiveScheduleResource.
     */
    public function getEffectiveSchedules(Menu $menu, Branch $branch): Collection
    {
        // Each branch menu owns its own schedule rows — cloneToBranch() copies
        // them from the master at clone time, and HQ schedule edits on a branch
        // menu write to that branch menu's own rows. Querying $menu->id directly
        // surfaces schedules added after the initial clone.
        return MenuSchedule::query()
            ->where('menu_schedules.menu_id', $menu->id)
            ->whereNull('menu_schedules.deleted_at')
            ->leftJoin('branch_schedule_overrides as o', function (JoinClause $join) use ($branch) {
                $join->on('o.menu_schedule_id', '=', 'menu_schedules.id')
                    ->where('o.branch_id', '=', $branch->id);
            })
            ->select([
                'menu_schedules.*',
                DB::raw('COALESCE(o.start_time, menu_schedules.start_time) as effective_start_time'),
                DB::raw('COALESCE(o.end_time,   menu_schedules.end_time)   as effective_end_time'),
                DB::raw('COALESCE(o.days_of_week, menu_schedules.days_of_week) as effective_days_of_week'),
                // Calendar window (#1970). NULL on BOTH sides is meaningful here —
                // it means unbounded — so the alias stays NULL rather than
                // falling back to a sentinel.
                DB::raw('COALESCE(o.start_date, menu_schedules.start_date) as effective_start_date'),
                DB::raw('COALESCE(o.end_date,   menu_schedules.end_date)   as effective_end_date'),
                DB::raw('menu_schedules.start_time as hq_start_time'),
                DB::raw('menu_schedules.end_time   as hq_end_time'),
                DB::raw('menu_schedules.days_of_week as hq_days_of_week'),
                DB::raw('menu_schedules.start_date as hq_start_date'),
                DB::raw('menu_schedules.end_date   as hq_end_date'),
                // Day-of-month override (#1979). The KIND itself is HQ-only —
                // a branch flipping it would reinterpret every other column on
                // the row rather than adjust it — so there is no effective/HQ
                // pair for that one.
                DB::raw('COALESCE(o.days_of_month, menu_schedules.days_of_month) as effective_days_of_month'),
                DB::raw('menu_schedules.days_of_month as hq_days_of_month'),
                DB::raw('CASE WHEN o.id IS NOT NULL THEN 1 ELSE 0 END as is_overridden'),
            ])
            ->with('scheduleDates')
            ->orderBy('menu_schedules.priority')
            ->orderBy('menu_schedules.created_at')
            ->get();
    }

    /**
     * Activate or pause a schedule for this branch.
     *
     * Branch menus own their own schedule rows (cloned from the master at
     * cloneToBranch() time — see getEffectiveSchedules()), so toggling
     * visibility writes `is_active` directly on the schedule row. No override
     * row is involved: the shop turning a window on/off only affects that
     * branch's copy of the schedule.
     */
    public function setActive(MenuSchedule $schedule, bool $isActive): MenuSchedule
    {
        $schedule->update(['is_active' => $isActive]);

        return $schedule;
    }

    /**
     * Create or update the branch override for a single schedule window.
     *
     * NULL semantics: passing `start_time => null` resets that field to the HQ
     * default (the DB column is written as NULL so COALESCE falls back to HQ value).
     * Do NOT strip nulls — array_filter would silently skip null resets.
     *
     * organization_id and updated_by_id are server-resolved — never trust request body.
     */
    public function upsertOverride(MenuSchedule $schedule, Branch $branch, array $data): BranchScheduleOverride
    {
        return DB::transaction(function () use ($schedule, $branch, $data) {
            // Branch uses console_organization_id; resolve local PK for the FK column.
            $organizationId = Organization::where('console_organization_id', $branch->console_organization_id)
                ->value('id');

            // Normalize HH:MM → HH:MM:SS so the DB column stores a consistent
            // format regardless of whether the client sent short or long form.
            foreach (['start_time', 'end_time'] as $field) {
                if (array_key_exists($field, $data) && is_string($data[$field]) && strlen($data[$field]) === 5) {
                    $data[$field] .= ':00';
                }
            }

            return BranchScheduleOverride::updateOrCreate(
                [
                    'menu_schedule_id' => $schedule->id,
                    'branch_id' => $branch->id,
                ],
                $data + [
                    'organization_id' => $organizationId,
                    'updated_by_id' => auth()->id(),
                ]
            );
        });
    }

    /**
     * Delete the branch override for a schedule, reverting to HQ defaults.
     *
     * Aborts with 404 if no override exists (idempotent deletes are not supported —
     * callers should check existence before calling or handle the 404).
     */
    public function deleteOverride(MenuSchedule $schedule, Branch $branch): void
    {
        $deleted = BranchScheduleOverride::where('menu_schedule_id', $schedule->id)
            ->where('branch_id', $branch->id)
            ->delete();

        abort_unless($deleted, 404);
    }

    /**
     * Hard-delete branch overrides when a menu schedule is force-deleted.
     *
     * Mirrors the DB ON DELETE CASCADE for environments (SQLite tests) where FK
     * enforcement is disabled.
     */
    public function cascadeDeleteBranchScheduleOverrides(MenuSchedule $schedule): void
    {
        BranchScheduleOverride::where('menu_schedule_id', $schedule->id)->delete();
    }
}
