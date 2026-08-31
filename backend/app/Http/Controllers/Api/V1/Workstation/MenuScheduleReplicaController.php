<?php

namespace App\Http\Controllers\Api\V1\Workstation;

use App\Http\Controllers\Controller;
use App\Models\BranchScheduleOverride;
use App\Models\Menu;
use App\Models\MenuSchedule;
use App\Omnify\Enums\MenuScheduleRecurrenceEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/workstation/menu-schedules
 *
 * Replica feed. The real schema stores schedules in the separate
 * `menu_schedules` table — NOT as a JSON `schedule` blob on Menu.
 * Columns: start_time, end_time, days_of_week (tinyInteger bitmask
 * where bit 0 = Sunday … bit 6 = Saturday), is_active, priority,
 * menu_id.
 *
 * We expand the bitmask into one row per active (menu, day_of_week)
 * pair so the workstation's local `menu_schedules` mirror can drive
 * /pos/menus/by-day/{dow} without a Cloud round-trip.
 *
 * Branch scope: only schedules belonging to a Menu of the device's
 * branch — we can't filter on menu_schedules directly because the
 * branch lives on the parent.
 */
class MenuScheduleReplicaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $device = $request->attributes->get('device');
        $branchId = $device?->branch_id;
        if (! $branchId) {
            return response()->json(['data' => [], 'generated_at' => now()->toIso8601String()]);
        }

        $menuIds = Menu::query()
            ->where('branch_id', $branchId)
            ->pluck('name', 'id');

        if ($menuIds->isEmpty()) {
            return response()->json(['data' => [], 'generated_at' => now()->toIso8601String()]);
        }

        // `start_date` / `end_date` ARE selected as of #1970. They used to be
        // withheld on purpose: Cloud's POS surface did not apply the calendar
        // window either (#1237), so shipping the columns alone would have made
        // the offline LAN POS STRICTER than the online one — a shop losing the
        // ability to sell the moment its internet drops. #1970 closes that gap
        // from the other end: Cloud's POS reads now apply the window, so the
        // feed must carry it or the mirror becomes the LOOSER of the two and
        // an expired campaign menu stays sellable exactly when the shop is
        // offline and nobody can see it happening.
        $schedules = MenuSchedule::query()
            ->whereIn('menu_id', $menuIds->keys())
            ->where('is_active', true)
            ->with('scheduleDates')
            ->get(['id', 'menu_id', 'days_of_week', 'recurrence_kind', 'days_of_month',
                'start_time', 'end_time', 'start_date', 'end_date', 'priority', 'is_active']);

        // Branch-level schedule overrides (shop can widen/narrow HQ hours) must
        // be folded in here so the offline LAN feed matches Cloud's live reads
        // (`MenuService::getCurrentMenu` + `listActiveBranchMenusForShopByDay`),
        // which both COALESCE the branch override time over the HQ default.
        // Without this the workstation mirror serves stale HQ hours whenever a
        // branch has overridden a window → online/offline divergence.
        $overrides = BranchScheduleOverride::query()
            ->whereIn('menu_schedule_id', $schedules->pluck('id'))
            ->where('branch_id', $branchId)
            ->get(['menu_schedule_id', 'start_time', 'end_time', 'start_date', 'end_date', 'days_of_month'])
            ->keyBy('menu_schedule_id');

        $rows = [];
        foreach ($schedules as $sch) {
            $mask = (int) $sch->days_of_week;
            // Per-column COALESCE(override, HQ) — mirrors Cloud exactly: an
            // override row supplies a time only when that column is non-null,
            // otherwise the HQ default stands.
            $override = $overrides->get($sch->id);
            $effectiveStart = $override && $override->start_time !== null ? $override->start_time : $sch->start_time;
            $effectiveEnd = $override && $override->end_time !== null ? $override->end_time : $sch->end_time;
            // Times stored as HH:MM:SS in DB; emit "" when null so the
            // workstation column NULL-handling stays simple. Priority
            // surfaces so the workstation's `handleLocalPosMenuByDay`
            // can pick the highest-priority match per (menu, dow) pair
            // — matches `MenuService::listActiveBranchMenusForShopByDay`
            // which orders by `menu_schedules.priority`.
            $startTime = $effectiveStart !== null ? (string) $effectiveStart : '';
            $endTime = $effectiveEnd !== null ? (string) $effectiveEnd : '';
            // Calendar window, same per-column COALESCE (#1970). getRawOriginal
            // because the model casts these to Date and `(string) $carbon` would
            // emit a full datetime the Go side does not parse; "" = unbounded,
            // matching the NULL semantics on both sides.
            $effectiveStartDate = $override?->getRawOriginal('start_date') ?? $sch->getRawOriginal('start_date');
            $effectiveEndDate = $override?->getRawOriginal('end_date') ?? $sch->getRawOriginal('end_date');
            // substr, not (string): SQLite keeps the cast's 'Y-m-d H:i:s' while
            // MySQL's DATE column truncates, and the Go side parses 'Y-m-d'.
            $startDate = $effectiveStartDate !== null ? substr((string) $effectiveStartDate, 0, 10) : '';
            $endDate = $effectiveEndDate !== null ? substr((string) $effectiveEndDate, 0, 10) : '';
            $priority = (int) ($sch->priority ?? 0);

            // Recurrence rule (#1979). The workstation gets the RULE, not a
            // pre-expanded list of dates: an expansion needs a horizon, and a
            // till that stays offline past that horizon would quietly go blank.
            // getRawOriginal: the model casts this to a backed enum, and a bare
            // (string) cast on an enum is a fatal, not a fallback.
            $kind = (string) ($sch->getRawOriginal('recurrence_kind') ?: MenuScheduleRecurrenceEnum::Weekly->value);
            $daysOfMonth = (int) ($override?->days_of_month ?? $sch->days_of_month ?? 0);
            $specificDates = $sch->scheduleDates
                ->map(fn ($row) => substr((string) $row->getRawOriginal('date'), 0, 10))
                ->sort()
                ->values()
                ->implode(',');

            // A non-weekly row has no weekday to flatten by, but the mirror's
            // `day_of_week` column is NOT NULL with a 0–6 CHECK. Emitting all
            // seven keeps that constraint honest and lets the weekday filter on
            // the Go side always pass, so the kind columns do the real work.
            // Cheap: these rows are a handful per shop.
            $isWeekly = $kind === MenuScheduleRecurrenceEnum::Weekly->value;

            for ($dow = 0; $dow < 7; $dow++) {
                if ($isWeekly && ($mask & (1 << $dow)) === 0) {
                    continue;
                }
                $rows[] = [
                    'id' => $sch->id.'-'.$dow,
                    'menu_id' => $sch->menu_id,
                    'menu_name' => (string) ($menuIds[$sch->menu_id] ?? ''),
                    'day_of_week' => $dow,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'recurrence_kind' => $kind,
                    'days_of_month' => $daysOfMonth,
                    'specific_dates' => $specificDates,
                    'priority' => $priority,
                    'is_active' => (bool) $sch->is_active,
                ];
            }
        }

        return response()->json([
            'data' => $rows,
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
