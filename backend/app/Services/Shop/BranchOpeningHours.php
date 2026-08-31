<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Support\BusinessClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * #1160 — reads `branches.weekly_hours` (the structured schedule the HQ shop
 * dialog already edits) and answers "is this instant inside opening hours?".
 *
 * Shape, per weekday key (`mon`…`sun`):
 *
 *     {"mon": {"open": "09:00", "close": "18:00", "closed": false},
 *      "sun": {"closed": true}}
 *
 * Rules:
 * - The comparison runs on the BRANCH's wall clock (#1091). A Hanoi customer
 *   scheduling a Tokyo pickup is judged against Tokyo's clock, never the
 *   app's, the DB's, or their own.
 * - An unconfigured schedule (null / empty / day with no open+close) is NOT a
 *   constraint. Most shops have never filled this in, and refusing every
 *   scheduled pickup because a JSON column is empty would be a worse bug than
 *   the one this guards. Configure hours to get enforcement.
 * - `close <= open` reads as an overnight window (18:00 → 02:00): the close
 *   belongs to the NEXT day, so a 01:00 pickup is still inside the previous
 *   day's window.
 */
final class BranchOpeningHours
{
    private const DAY_KEYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    /**
     * Is the instant inside an opening window? True when unconfigured.
     */
    public static function isOpenAt(Branch $branch, DateTimeInterface $instant): bool
    {
        if (! self::isConfigured($branch)) {
            return true;
        }

        $at = self::atBranch($branch, $instant);

        // An overnight window that opened YESTERDAY still covers an
        // after-midnight pickup, whatever today's own entry says.
        $overnight = self::windowStartingOn($branch, $at->subDay());
        if ($overnight !== null && $at >= $overnight['open'] && $at <= $overnight['close']) {
            return true;
        }

        $entry = self::entryFor($branch, $at);

        // Day absent from the schedule — not a constraint (see the class note:
        // enforcement follows what the shop actually published).
        if ($entry === null) {
            return true;
        }

        // The one explicit "we are shut" signal.
        if (($entry['closed'] ?? false) === true) {
            return false;
        }

        $window = self::windowStartingOn($branch, $at);

        // Published but unusable (missing or malformed times) — don't invent a
        // constraint from a broken row; that would refuse every pickup at a
        // shop whose admin fat-fingered one field.
        if ($window === null) {
            return true;
        }

        return $at >= $window['open'] && $at <= $window['close'];
    }

    /**
     * The closing instant of the window the given instant falls in, or of the
     * day it lands on when it is outside every window — what the customer
     * needs quoted back ("we close at 22:00"). Null when unconfigured or the
     * branch is closed that whole day.
     */
    public static function closingAt(Branch $branch, DateTimeInterface $instant): ?CarbonImmutable
    {
        if (! self::isConfigured($branch)) {
            return null;
        }

        $at = self::atBranch($branch, $instant);

        foreach ([$at->subDay(), $at] as $anchor) {
            $window = self::windowStartingOn($branch, $anchor);

            if ($window !== null && $at >= $window['open'] && $at <= $window['close']) {
                return $window['close'];
            }
        }

        return self::windowStartingOn($branch, $at)['close'] ?? null;
    }

    /**
     * The next instant the branch opens AFTER the given one — what a customer
     * who arrived too early (or too late) needs quoted back ("we open again at
     * 06:00 tomorrow"). Null when unconfigured, or when the published schedule
     * never opens within the following week.
     */
    public static function nextOpeningAt(Branch $branch, DateTimeInterface $instant): ?CarbonImmutable
    {
        if (! self::isConfigured($branch)) {
            return null;
        }

        $at = self::atBranch($branch, $instant);

        // Today first (a shop shut at 05:00 opens at 06:00 the SAME day), then
        // the next seven — enough to wrap the whole week for a shop that opens
        // on one weekday only.
        for ($offset = 0; $offset <= 7; $offset++) {
            $window = self::windowStartingOn($branch, $at->addDays($offset));

            if ($window !== null && $window['open'] > $at) {
                return $window['open'];
            }
        }

        return null;
    }

    /**
     * Does the branch publish a usable schedule at all?
     */
    public static function isConfigured(Branch $branch): bool
    {
        return is_array($branch->weekly_hours) && $branch->weekly_hours !== [];
    }

    /**
     * The opening window that STARTS on the given branch-local day, or null
     * when that day is marked closed / has no usable open+close pair.
     *
     * @return array{open: CarbonImmutable, close: CarbonImmutable}|null
     */
    private static function windowStartingOn(Branch $branch, CarbonImmutable $day): ?array
    {
        $entry = self::entryFor($branch, $day);

        if ($entry === null || ($entry['closed'] ?? false) === true) {
            return null;
        }

        $open = self::parseTime($day, $entry['open'] ?? null);
        $close = self::parseTime($day, $entry['close'] ?? null);

        if ($open === null || $close === null) {
            return null;
        }

        // Overnight window — the close belongs to the following day.
        if ($close <= $open) {
            $close = $close->addDay();
        }

        return ['open' => $open, 'close' => $close];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function entryFor(Branch $branch, CarbonImmutable $day): ?array
    {
        $hours = $branch->weekly_hours;

        if (! is_array($hours)) {
            return null;
        }

        $entry = $hours[self::DAY_KEYS[(int) $day->dayOfWeek]] ?? null;

        return is_array($entry) ? $entry : null;
    }

    private static function parseTime(CarbonImmutable $day, mixed $time): ?CarbonImmutable
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $day->setTime($hour, $minute);
    }

    private static function atBranch(Branch $branch, DateTimeInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)
            ->setTimezone(BusinessClock::timezoneForBranch($branch->id));
    }
}
