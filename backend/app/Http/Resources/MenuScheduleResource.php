<?php

/**
 * MenuScheduleResource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\MenuSchedule\Resources\MenuScheduleResourceBase;
use Illuminate\Http\Request;

class MenuScheduleResource extends MenuScheduleResourceBase
{
    /** @var array<int, string> Day label indexed by bit position (0=Sun … 6=Sat) */
    private const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    public function toArray(Request $request): array
    {
        return array_merge($this->schemaArray($request), [
            'days_of_week_labels' => $this->decodeDaysOfWeek((int) $this->days_of_week),
            // Decoded day numbers for recurrence_kind = Monthly (#1979). The
            // bitmask ships too, but a client that has to decode 31 bits to
            // render a grid will decode them differently from the way this
            // server encoded them, sooner or later.
            'days_of_month_list' => $this->decodeDaysOfMonth((int) ($this->days_of_month ?? 0)),
            // Plain Y-m-d strings for recurrence_kind = SpecificDates. Empty for
            // the other kinds — never null, so the client can map() blind.
            'specific_dates' => $this->whenLoaded(
                'scheduleDates',
                fn () => $this->scheduleDates
                    ->map(fn ($row) => substr((string) $row->getRawOriginal('date'), 0, 10))
                    ->sort()
                    ->values()
                    ->all(),
                []
            ),
        ]);
    }

    /**
     * Decode a days_of_month bitmask into ascending day numbers (bit0 = the 1st).
     *
     * @return array<int, int>
     */
    private function decodeDaysOfMonth(int $bitmask): array
    {
        $days = [];
        for ($bit = 0; $bit <= 30; $bit++) {
            if (($bitmask >> $bit) & 1) {
                $days[] = $bit + 1;
            }
        }

        return $days;
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
