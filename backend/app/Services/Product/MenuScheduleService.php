<?php

/**
 * MenuScheduleService
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Product;

use App\Models\BranchScheduleOverride;
use App\Models\Menu;
use App\Models\MenuSchedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MenuScheduleService
{
    /**
     * List all non-deleted schedule windows for a menu, ordered for display.
     */
    public function listForMenu(Menu $menu): Collection
    {
        return $menu->menuSchedules()
            // Eager-loaded so MenuScheduleResource can emit `specific_dates`
            // (#1979) — whenLoaded() there means a missing relation silently
            // becomes an empty list, which reads as "this row names no dates"
            // rather than "we forgot to load them".
            ->with('scheduleDates')
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Create a new schedule window attached to the given menu.
     * Sets created_by_id from the authenticated user.
     */
    public function create(Menu $menu, array $data): MenuSchedule
    {
        return DB::transaction(function () use ($menu, $data) {
            $data = $this->normalizeTimes($data);
            $data['menu_id'] = $menu->id;
            $data['created_by_id'] = auth()->id();
            $data['is_active'] = $data['is_active'] ?? true;
            $data['priority'] = ($menu->menuSchedules()->max('priority') ?? 0) + 1;

            $dates = $this->pullSpecificDates($data);
            $schedule = MenuSchedule::create($data);
            $this->syncSpecificDates($schedule, $dates);

            return $schedule;
        });
    }

    /**
     * Update an existing schedule window.
     */
    public function update(MenuSchedule $schedule, array $data): MenuSchedule
    {
        return DB::transaction(function () use ($schedule, $data) {
            $dates = $this->pullSpecificDates($data);
            $schedule->update($this->normalizeTimes($data));
            $this->syncSpecificDates($schedule, $dates);

            return $schedule->fresh();
        });
    }

    /**
     * Lift `specific_dates` out of the payload — it is a related table, not a
     * column, so leaving it in would make Eloquent throw on an unknown attribute.
     *
     * Returns null when the key is ABSENT, which is not the same as an empty
     * array: absent means "this request says nothing about the dates" (a PATCH
     * of the times must not wipe them), empty means "clear them".
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>|null
     */
    private function pullSpecificDates(array &$data): ?array
    {
        if (! array_key_exists('specific_dates', $data)) {
            return null;
        }

        $dates = $data['specific_dates'];
        unset($data['specific_dates']);

        return array_values(array_unique(array_map('strval', (array) $dates)));
    }

    /**
     * Replace the row's explicit dates wholesale.
     *
     * Full replace rather than a diff: the list is a SET, so a diff buys nothing
     * but a second way to be wrong. `organization_id` is server-resolved from the
     * parent menu — never accepted from the request.
     *
     * @param  array<int, string>|null  $dates
     */
    private function syncSpecificDates(MenuSchedule $schedule, ?array $dates): void
    {
        if ($dates === null) {
            return;
        }

        $schedule->scheduleDates()->delete();

        if ($dates === []) {
            return;
        }

        $organizationId = $schedule->menu?->organization_id;

        $schedule->scheduleDates()->createMany(
            array_map(fn (string $date) => [
                'date' => $date,
                'organization_id' => $organizationId,
            ], $dates)
        );
    }

    /**
     * Normalize HH:MM input to HH:MM:SS so the TIME column stores a
     * consistent format regardless of which form the client sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTimes(array $data): array
    {
        foreach (['start_time', 'end_time'] as $field) {
            if (array_key_exists($field, $data) && is_string($data[$field]) && strlen($data[$field]) === 5) {
                $data[$field] .= ':00';
            }
        }

        return $data;
    }

    /**
     * Soft-delete a schedule window. Falls back to always-on for the parent
     * menu if no non-deleted rows remain after deletion.
     */
    public function delete(MenuSchedule $schedule): bool
    {
        return DB::transaction(function () use ($schedule) {
            return (bool) $schedule->delete();
        });
    }

    public function cascadeDeleteBranchOverrides(string $menuScheduleId): void
    {
        BranchScheduleOverride::where('menu_schedule_id', $menuScheduleId)->delete();
    }

    /**
     * Reorder schedule windows using the same 2-phase UPDATE strategy as MenuService::reorderMenus().
     *
     * Phase 1: negate all priorities to free the positive slots.
     * Phase 2: assign sequential 1-based priorities per the ordered IDs.
     *
     * @param  array<int, string>  $scheduleIds  ordered schedule IDs from the UI
     */
    public function reorder(Menu $menu, array $scheduleIds): void
    {
        if (count($scheduleIds) !== count(array_unique($scheduleIds))) {
            throw ValidationException::withMessages([
                'schedule_ids' => ['schedule_ids must not contain duplicate IDs.'],
            ]);
        }

        $total = $menu->menuSchedules()->count();

        $matched = $menu->menuSchedules()->whereIn('id', $scheduleIds)->count();

        if ($matched !== count($scheduleIds)) {
            throw ValidationException::withMessages([
                'schedule_ids' => ['One or more schedule IDs do not belong to this menu.'],
            ]);
        }

        if ($total !== count($scheduleIds)) {
            throw ValidationException::withMessages([
                'schedule_ids' => ["schedule_ids must cover all schedules. Expected {$total} IDs, received ".count($scheduleIds).'.'],
            ]);
        }

        DB::transaction(function () use ($menu, $scheduleIds) {
            // Phase 1: move to negative values to release the positive slots
            $menu->menuSchedules()
                ->whereIn('id', $scheduleIds)
                ->update(['priority' => DB::raw('0 - priority')]);

            // Phase 2: assign final 1-based priority
            foreach ($scheduleIds as $index => $id) {
                $menu->menuSchedules()
                    ->where('id', $id)
                    ->update(['priority' => $index + 1]);
            }
        });
    }
}
