<?php

/**
 * FloatingSectionScheduleService
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Services\Product;

use App\Models\FloatingSection;
use App\Models\FloatingSectionSchedule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mirrors MenuScheduleService — same CRUD + reorder shape, parented to a
 * FloatingSection instead of a Menu.
 */
class FloatingSectionScheduleService
{
    public function listForSection(FloatingSection $floatingSection): Collection
    {
        return $floatingSection->schedules()
            // Branch clones link back to their HQ source row (see
            // FloatingSectionService::cloneToBranch) — loaded so the shop
            // schedule table can show "Giờ HQ" alongside its own effective
            // time, same as Menu's shop schedule page.
            ->with('masterSchedule')
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();
    }

    public function create(FloatingSection $floatingSection, array $data): FloatingSectionSchedule
    {
        return DB::transaction(function () use ($floatingSection, $data) {
            $data = $this->normalizeTimes($data);
            $data['floating_section_id'] = $floatingSection->id;
            $data['created_by_id'] = auth()->id();
            $data['is_active'] = $data['is_active'] ?? true;
            $data['priority'] = ($floatingSection->schedules()->max('priority') ?? 0) + 1;

            return FloatingSectionSchedule::create($data);
        });
    }

    public function update(FloatingSectionSchedule $schedule, array $data): FloatingSectionSchedule
    {
        return DB::transaction(function () use ($schedule, $data) {
            $schedule->update($this->normalizeTimes($data));

            return $schedule->fresh();
        });
    }

    /**
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

    public function delete(FloatingSectionSchedule $schedule): bool
    {
        return DB::transaction(function () use ($schedule) {
            return (bool) $schedule->delete();
        });
    }

    /**
     * Flip is_active only — used by the shop-side toggle endpoint, which is
     * restricted to on/off and must not accept arbitrary field edits.
     */
    public function toggleActive(FloatingSectionSchedule $schedule): FloatingSectionSchedule
    {
        return DB::transaction(function () use ($schedule) {
            $schedule->update(['is_active' => ! $schedule->is_active]);

            return $schedule->fresh();
        });
    }

    /**
     * Shop overrides the displayed time window on its own clone row —
     * start_time/end_time/days_of_week only (start_date/end_date and
     * is_active stay HQ-owned / shop-toggle-owned respectively). Marks the
     * row so a later syncFromMaster() stops pulling HQ's timing down.
     *
     * @param  array<string, mixed>  $data
     */
    public function overrideTime(FloatingSectionSchedule $schedule, array $data): FloatingSectionSchedule
    {
        return DB::transaction(function () use ($schedule, $data) {
            $schedule->update([
                ...$this->normalizeTimes($data),
                'is_time_overridden' => true,
            ]);

            return $schedule->fresh();
        });
    }

    /**
     * Reset a branch schedule's time back to its master's CURRENT window.
     * Mirrors resetSkuPrice's "re-read from source" pattern.
     */
    public function resetTimeOverride(FloatingSectionSchedule $schedule): FloatingSectionSchedule
    {
        return DB::transaction(function () use ($schedule) {
            $schedule->loadMissing('masterSchedule');
            $master = $schedule->masterSchedule;

            $schedule->update([
                'start_time' => $master?->getRawOriginal('start_time') ?? $schedule->getRawOriginal('start_time'),
                'end_time' => $master?->getRawOriginal('end_time') ?? $schedule->getRawOriginal('end_time'),
                'days_of_week' => $master?->days_of_week ?? $schedule->days_of_week,
                'is_time_overridden' => false,
            ]);

            return $schedule->fresh();
        });
    }

    /**
     * @param  array<int, string>  $scheduleIds
     */
    public function reorder(FloatingSection $floatingSection, array $scheduleIds): void
    {
        if (count($scheduleIds) !== count(array_unique($scheduleIds))) {
            throw ValidationException::withMessages([
                'schedule_ids' => ['schedule_ids must not contain duplicate IDs.'],
            ]);
        }

        $total = $floatingSection->schedules()->count();
        $matched = $floatingSection->schedules()->whereIn('id', $scheduleIds)->count();

        if ($matched !== count($scheduleIds)) {
            throw ValidationException::withMessages([
                'schedule_ids' => ['One or more schedule IDs do not belong to this floating section.'],
            ]);
        }

        if ($total !== count($scheduleIds)) {
            throw ValidationException::withMessages([
                'schedule_ids' => ["schedule_ids must cover all schedules. Expected {$total} IDs, received ".count($scheduleIds).'.'],
            ]);
        }

        DB::transaction(function () use ($floatingSection, $scheduleIds) {
            $floatingSection->schedules()
                ->whereIn('id', $scheduleIds)
                ->update(['priority' => DB::raw('0 - priority')]);

            foreach ($scheduleIds as $index => $id) {
                $floatingSection->schedules()
                    ->where('id', $id)
                    ->update(['priority' => $index + 1]);
            }
        });
    }
}
