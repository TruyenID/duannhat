<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * #1091 — the ONE clock for business time.
 *
 * Rule (docs/guide/business-time.md): every BUSINESS decision — business_date,
 * shift boundaries, menu/promotion windows, expiry, per-day reports — is taken
 * in the BRANCH's timezone (`branches.timezone`), never the app timezone
 * (UTC), never the viewing user's timezone. A manager in Hanoi looking at a
 * Tokyo branch must see Tokyo business dates. User timezones are a
 * presentation concern only (see SetTimezone middleware).
 *
 * Storage is unaffected: instants stay UTC in the database; this class only
 * answers "what wall-clock/date is it AT THE BRANCH".
 */
final class BusinessClock
{
    /** Memo value for a missing branch row or an unusable stored value. */
    private const NOT_STORED = '';

    /**
     * #1161 — per-process memo of the timezone STORED on each branch.
     *
     * This stores the branch value rather than the resolved fallback. Otherwise
     * the first caller to supply a domain-specific fallback would poison every
     * later caller in the same process. `warmFor()` collapses a whole sweep to
     * one indexed query; the memo is flushed between queued jobs so an admin
     * timezone edit is stale for at most one job.
     *
     * @var array<string, string>
     */
    private static array $timezoneMemo = [];

    /**
     * Operating country for each branch, loaded by the same query as timezone.
     *
     * @var array<string, string>
     */
    private static array $countryMemo = [];

    /**
     * The branch's IANA timezone, with a country-aware fallback chain.
     *
     * Stored branch timezone always wins. When it is absent or invalid, a
     * domain-specific fallback wins next, then the head-office timezone for the
     * branch organization country, then the global operations timezone.
     */
    public static function timezoneForBranch(?string $branchId, ?string $domainFallback = null): string
    {
        if ($branchId === null || $branchId === '') {
            return $domainFallback ?? self::headOfficeTimezone(null);
        }

        self::load([$branchId]);

        $stored = self::$timezoneMemo[$branchId] ?? self::NOT_STORED;
        if ($stored !== self::NOT_STORED) {
            return $stored;
        }

        return $domainFallback ?? self::headOfficeTimezone(self::$countryMemo[$branchId] ?? null);
    }

    /**
     * Head-office timezone for an ISO-3166 alpha-2 operating country.
     *
     * Countries spanning several zones are intentionally not expanded here:
     * their branches must carry their own IANA timezone. The map is only a
     * fallback for a branch whose timezone data is missing.
     */
    public static function headOfficeTimezone(?string $country): string
    {
        $country = strtoupper(trim((string) $country));
        $mapped = $country === ''
            ? null
            : ((array) config('app.operations_timezones', []))[$country] ?? null;

        if (is_string($mapped) && $mapped !== '') {
            return $mapped;
        }

        return (string) config('app.operations_timezone', 'Asia/Tokyo');
    }

    /**
     * Batch-resolve timezones for a set of branch ids in ONE `branches` read.
     *
     * Call once per chunk in any sweep that then asks per-row questions
     * (businessDate / now / timezoneForBranch) — the per-row calls become memo
     * hits. Ids already memoized are skipped, so a mixed batch still costs a
     * single query for the unknown remainder, and zero when nothing is new.
     *
     * @param  iterable<mixed>  $branchIds
     */
    public static function warmFor(iterable $branchIds): void
    {
        $ids = [];
        foreach ($branchIds as $id) {
            if ($id !== null && (string) $id !== '') {
                $ids[] = (string) $id;
            }
        }

        self::load($ids);
    }

    /** Reset the memo — for tests, and for any long-lived worker between jobs. */
    public static function flushTimezoneMemo(): void
    {
        self::$timezoneMemo = [];
        self::$countryMemo = [];
    }

    /**
     * Resolve all not-yet-known branches in one statement.
     *
     * @param  list<string>  $branchIds
     */
    private static function load(array $branchIds): void
    {
        $missing = array_values(array_unique(array_filter(
            $branchIds,
            static fn (string $id): bool => ! array_key_exists($id, self::$timezoneMemo),
        )));

        if ($missing === []) {
            return;
        }

        // Memoise even dangling ids. Otherwise one bad foreign key turns every
        // call into another database query for the lifetime of the process.
        foreach ($missing as $id) {
            self::$timezoneMemo[$id] = self::NOT_STORED;
            self::$countryMemo[$id] = self::NOT_STORED;
        }

        $rows = DB::table('branches')
            ->leftJoin(
                'organizations',
                'organizations.console_organization_id',
                '=',
                'branches.console_organization_id',
            )
            ->whereIn('branches.id', $missing)
            ->get([
                'branches.id as branch_id',
                'branches.timezone as timezone',
                'organizations.operating_country as operating_country',
            ]);

        foreach ($rows as $row) {
            $branchId = (string) $row->branch_id;
            $country = strtoupper(trim((string) ($row->operating_country ?? '')));
            self::$countryMemo[$branchId] = $country;

            $timezone = $row->timezone;
            if (! is_string($timezone) || $timezone === '') {
                Log::warning('business_clock_branch_timezone_fallback', [
                    'branch_id' => $branchId,
                    'operating_country' => $country === self::NOT_STORED ? null : $country,
                ]);

                continue;
            }

            if (in_array($timezone, timezone_identifiers_list(), true)) {
                self::$timezoneMemo[$branchId] = $timezone;

                continue;
            }

            Log::warning('business_clock_invalid_branch_timezone', [
                'branch_id' => $branchId,
                'timezone' => $timezone,
                'operating_country' => $country === self::NOT_STORED ? null : $country,
            ]);
        }
    }

    /** Current instant expressed in the branch's wall clock. Honors Carbon::setTestNow(). */
    public static function now(?string $branchId): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezoneForBranch($branchId));
    }

    /** Today's business date (Y-m-d) at the branch. */
    public static function businessDate(?string $branchId): string
    {
        return self::now($branchId)->toDateString();
    }

    /**
     * The business date a given UTC instant falls on AT THE BRANCH — for
     * events whose wall time is supplied (e.g. a workstation-synced shift
     * open that happened while offline), not "now".
     */
    public static function businessDateAt(?string $branchId, DateTimeInterface $instant): string
    {
        return CarbonImmutable::instance($instant)
            ->setTimezone(self::timezoneForBranch($branchId))
            ->toDateString();
    }

    /**
     * #1091 — UTC instant bounds for a BRANCH-local date range filter.
     *
     * A user filtering "2026-07-27" means the branch's 27th, so the correct
     * predicate on a UTC timestamp column is
     *   created_at >= 27th 00:00 branch-time (as UTC)
     *   created_at <  28th 00:00 branch-time (as UTC, exclusive)
     * — never `whereDate(created_at, …)`, which compares against the DB
     * session's (UTC) calendar and shifts every row within `|tz offset|` of
     * midnight into the wrong day.
     *
     * Returns [fromStartUtc, untilExclusiveUtc]; each side is null when its
     * input date is null/empty. Malformed input dates also yield null (the
     * filter is simply not applied — same behaviour the old code had for
     * garbage input).
     *
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    public static function utcRangeForBusinessDates(?string $branchId, ?string $fromDate, ?string $untilDate): array
    {
        $tz = self::timezoneForBranch($branchId);

        $parse = static function (?string $date) use ($tz): ?CarbonImmutable {
            if ($date === null || trim($date) === '') {
                return null;
            }
            try {
                return CarbonImmutable::parse($date, $tz)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $from = $parse($fromDate)?->utc();
        $until = $parse($untilDate)?->addDay()->utc();

        return [$from, $until];
    }
}
