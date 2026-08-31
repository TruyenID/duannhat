<?php

/**
 * FloatingSectionScheduleResource
 *
 * SAFE TO EDIT - This file is never overwritten by Omnify.
 */

namespace App\Http\Resources;

use App\Omnify\Modules\FloatingSectionSchedule\Resources\FloatingSectionScheduleResourceBase;
use Illuminate\Http\Request;

class FloatingSectionScheduleResource extends FloatingSectionScheduleResourceBase
{
    /** @var array<int, string> Day label indexed by bit position (0=Sun … 6=Sat) */
    private const DAY_LABELS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    public function toArray(Request $request): array
    {
        return array_merge($this->schemaArray($request), [
            'days_of_week_labels' => $this->decodeDaysOfWeek((int) $this->days_of_week),
        ]);
    }

    /**
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
