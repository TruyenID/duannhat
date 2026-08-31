<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * "Is this schedule row on, on a given calendar day, at a given branch?"
 *
 * ONE definition, applied at every reading surface (#1979). There are four of
 * them — the guest read, the POS day picker, the single-live-menu read, and the
 * feed the offline LAN till mirrors — and before this class each carried its own
 * hand-rolled copy of the day filter. #1970 already showed what that costs: the
 * surfaces disagreed about the calendar window for four months, so one shop gave
 * two answers to "which menu is on sale" depending on which screen you stood in
 * front of. Adding two more recurrence kinds to four separate copies would have
 * re-created that in a week.
 *
 * A row is on when ALL of:
 *   1. the calendar window contains the day (#1970) — NULL on either bound means
 *      unbounded, and the branch override wins over HQ per column;
 *   2. its recurrence kind matches the day:
 *      - Weekly        → `days_of_week` bitmask, bit0=Sun … bit6=Sat
 *      - Monthly       → `days_of_month` bitmask, bit0=1st … bit30=31st
 *      - SpecificDates → a `menu_schedule_dates` row for that exact date
 *
 * WHY THE KINDS ARE MUTUALLY EXCLUSIVE, not additive: a row that carried both a
 * weekday mask and a day-of-month mask would have to answer whether "Monday AND
 * the 15th" means the intersection (almost never what anyone wants) or the union
 * (in which case the weekday box silently stops meaning anything). A menu's
 * schedule ROWS already OR together at every caller, so "the 1st and 15th, plus
 * 20 Aug" is two rows and needs no new combining rule at all.
 *
 * `DATE()` around every date comparison is load-bearing, not cosmetic — see
 * `effectiveDateExpr()`.
 */
class MenuScheduleDateRule
{
    /**
     * Constrain a query over `menu_schedules` to rows that are on, on $onDate.
     *
     * @param  Builder|Relation<*, *, *>  $query
     * @param  int  $dayOfWeek  0 (Sunday) … 6 (Saturday). Passed separately rather
     *                          than derived from $onDate because the POS day
     *                          picker asks for an explicit weekday ("show me
     *                          Tuesday"), while the calendar-driven kinds can
     *                          only ever mean the real date.
     * @param  string  $onDate  `Y-m-d` in the BRANCH's business time (#1091) —
     *                          never the app clock, or a Tokyo shop read from
     *                          Hanoi answers for the wrong day.
     */
    public static function apply(Builder|Relation $query, string $branchId, int $dayOfWeek, string $onDate): void
    {
        self::applyCalendarWindow($query, $branchId, $onDate);
        self::applyRecurrence($query, $branchId, $dayOfWeek, $onDate);
    }

    /**
     * The #1970 calendar window on its own, for callers that filter the
     * recurrence some other way (the replica feed flattens by weekday itself).
     *
     * @param  Builder|Relation<*, *, *>  $query
     */
    public static function applyCalendarWindow(Builder|Relation $query, string $branchId, string $onDate): void
    {
        $start = self::effectiveDateExpr('start_date');
        $end = self::effectiveDateExpr('end_date');

        $query
            ->whereRaw("({$start} IS NULL OR {$start} <= ?)", [$branchId, $branchId, $onDate])
            ->whereRaw("({$end} IS NULL OR {$end} >= ?)", [$branchId, $branchId, $onDate]);
    }

    /**
     * The recurrence-kind match on its own.
     *
     * @param  Builder|Relation<*, *, *>  $query
     */
    public static function applyRecurrence(Builder|Relation $query, string $branchId, int $dayOfWeek, string $onDate): void
    {
        $weekBit = 1 << $dayOfWeek;
        // bit0 = the 1st, so the Nth day of the month is bit N-1.
        $monthBit = 1 << ((int) date('j', strtotime($onDate)) - 1);

        $weekdays = self::effectiveMaskExpr('days_of_week');
        $monthdays = self::effectiveMaskExpr('days_of_month');
        // COALESCE on the kind itself: a row written before #1979 has the column
        // defaulted, but a hand-inserted fixture may not, and reading NULL as
        // "no kind matches" would silently switch that menu off.
        $kind = "COALESCE(menu_schedules.recurrence_kind, 'Weekly')";

        $query->where(function ($q) use ($kind, $weekdays, $monthdays, $weekBit, $monthBit, $branchId, $onDate) {
            $q
                ->whereRaw("({$kind} = 'Weekly' AND ({$weekdays} & ?) > 0)", [$branchId, $weekBit])
                ->orWhereRaw("({$kind} = 'Monthly' AND ({$monthdays} & ?) > 0)", [$branchId, $monthBit])
                ->orWhereRaw(
                    "({$kind} = 'SpecificDates' AND EXISTS (
                        SELECT 1 FROM menu_schedule_dates msd
                        WHERE msd.menu_schedule_id = menu_schedules.id
                          AND DATE(msd.date) = ?
                    ))",
                    [$onDate]
                );
        });
    }

    /**
     * `COALESCE(branch override, HQ)` for a date column, wrapped in `DATE()`.
     *
     * The wrapper is load-bearing. Laravel's `date` cast writes through
     * `getDateFormat()`, so a row saved via Eloquent lands as
     * `'2026-02-10 00:00:00'` in SQLite (what the tests run) while MySQL's DATE
     * column truncates to `'2026-02-10'`. Comparing those as plain strings makes
     * the two engines disagree ON THE BOUNDARY DAY ITSELF — a campaign's first
     * day live on MySQL and hidden on SQLite, which is the kind of split that
     * only ever shows up in production. `DATE(NULL)` is still NULL, so the
     * unbounded arm is untouched.
     *
     * Two `?` placeholders (both the branch id) — the subquery is repeated
     * rather than aliased because the `IS NULL` arm has to survive the COALESCE
     * and a WHERE clause cannot reference its own SELECT alias.
     */
    private static function effectiveDateExpr(string $column): string
    {
        return "DATE(COALESCE(
            (SELECT bso.{$column} FROM branch_schedule_overrides bso
             WHERE bso.menu_schedule_id = menu_schedules.id AND bso.branch_id = ?),
            menu_schedules.{$column}
        ))";
    }

    /**
     * `COALESCE(branch override, HQ)` for a bitmask column. One `?` (the branch
     * id). `$column` is a compile-time constant, so interpolation is safe.
     */
    private static function effectiveMaskExpr(string $column): string
    {
        return "COALESCE(
            (SELECT bsm.{$column} FROM branch_schedule_overrides bsm
             WHERE bsm.menu_schedule_id = menu_schedules.id AND bsm.branch_id = ?),
            menu_schedules.{$column}
        )";
    }
}
