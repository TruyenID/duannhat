<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchEffectiveScheduleResource extends JsonResource
{
    /** @var array<int, string> Day label indexed by bit position (0=Sun … 6=Sat) */
    private const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /**
     * effective_start_time, effective_end_time, hq_start_time, hq_end_time,
     * and is_overridden are SQL aliases hydrated as dynamic model properties by
     * BranchMenuScheduleService::getEffectiveSchedules(). They fall back to the
     * raw HQ column values if the alias is absent (safety net — not expected in production).
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'start_time' => $this->effective_start_time ?? $this->getRawOriginal('start_time'),
            'end_time' => $this->effective_end_time ?? $this->getRawOriginal('end_time'),
            'days_of_week' => (int) ($this->effective_days_of_week ?? $this->days_of_week),
            'days_of_week_labels' => $this->decodeDaysOfWeek((int) ($this->effective_days_of_week ?? $this->days_of_week)),
            // Calendar window (#1970). NULL means unbounded on both the effective
            // and the HQ side, so `??` would be wrong here — an absent alias and
            // a deliberate NULL are the same answer and both must stay null.
            'recurrence_kind' => $this->recurrence_kind,
            'days_of_month' => (int) ($this->effective_days_of_month ?? 0),
            'specific_dates' => $this->whenLoaded(
                'scheduleDates',
                fn () => $this->scheduleDates
                    ->map(fn ($row) => substr((string) $row->getRawOriginal('date'), 0, 10))
                    ->sort()->values()->all(),
                []
            ),
            'start_date' => $this->asDate($this->effective_start_date),
            'end_date' => $this->asDate($this->effective_end_date),
            'is_active' => (bool) $this->is_active,
            'priority' => $this->priority,
            'is_overridden' => (bool) $this->is_overridden,
            'hq_defaults' => [
                'start_time' => $this->hq_start_time ?? $this->getRawOriginal('start_time'),
                'end_time' => $this->hq_end_time ?? $this->getRawOriginal('end_time'),
                'days_of_week' => (int) ($this->hq_days_of_week ?? $this->days_of_week),
                'days_of_week_labels' => $this->decodeDaysOfWeek((int) ($this->hq_days_of_week ?? $this->days_of_week)),
                'days_of_month' => (int) ($this->hq_days_of_month ?? 0),
                'start_date' => $this->asDate($this->hq_start_date),
                'end_date' => $this->asDate($this->hq_end_date),
            ],
        ];
    }

    /**
     * Normalise a raw calendar-window value to `Y-m-d`.
     *
     * The SQL aliases carry whatever the engine stored, and the 'date' cast
     * writes through getDateFormat() — so the same row reads back as
     * '2026-02-10' on MySQL and '2026-02-10 00:00:00' on SQLite. Clients get one
     * shape either way. NULL stays NULL: it means unbounded, not "unknown".
     */
    private function asDate(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }

    /**
     * Decode a days_of_week bitmask into an ordered array of day labels.
     * Bit0=Sun … Bit6=Sat; matches PHP Carbon dayOfWeek (0=Sun…6=Sat).
     *
     * @return array<int, string>
     */
    private function decodeDaysOfWeek(int $bitmask): array
    {
        $labels = [];
        for ($bit = 0; $bit <= 6; $bit++) {
            if (($bitmask >> $bit) & 1) {
                $labels[] = self::DAY_LABELS[$bit];
            }
        }

        return $labels;
    }
}
