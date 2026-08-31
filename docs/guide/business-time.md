---
title: Business time — one clock per branch
category: guide
tags: [timezone, business-time, business-clock, issue-1091]
summary: "Business time is always the branch timezone reached through BusinessClock; display time may follow the signed-in user but only in the presentation layer. Includes the forbidden patterns and the 3-timezone test matrix."
related: [tax-types, cashier-shift-recovery]
---

# Business time — the one-clock rule (#1091)

The system runs VN branches (`Asia/Ho_Chi_Minh`, +7) and JP branches
(`Asia/Tokyo`, +9) on a single backend with `app.timezone = UTC`. The mandatory
rules:

| Kind of time | Clock | How to use it |
|---|---|---|
| **Business time** — `business_date`, shift boundaries, menu/promotion windows, lot expiry, day-grouped reports, per-day order counters | **`branches.timezone`** | `\App\Support\BusinessClock::{now,businessDate,businessDateAt,timezoneForBranch}($branchId)` |
| **Storage** — every datetime column | **UTC instant** | Store the instant; convert to branch time **when reading or deciding** |
| **Display time** — what the signed-in person sees | User timezone | Presentation layer only (`SetTimezone::ATTRIBUTE` — DISPLAY ONLY, never for business logic) |

A manager in Hanoi opening a Tokyo branch report must see the **Tokyo business
day**. Never take the viewer's timezone for a business decision.

## Migrated to BusinessClock (2026-07-26)

- `TillSession.business_date` — both the POS open path
  (`TillSessionService::open`) and workstation sync-UP (the instant supplied by
  the workstation goes through `businessDateAt`). A shift opened at 08:00 JST on
  a Saturday now carries Saturday as its `business_date`.
- `MenuService::getCurrentMenu` — schedule windows are evaluated against the
  branch wall clock (consistent with `MenuPromotionService`, which was the only
  correct example to begin with).
- `MenuPromotionService::resolveBranchTimezone` — delegates to BusinessClock (one
  path only).
- `CustomerMenuService::resolveBranchTimezone` plus `Workstation\MenuController`
  (2026-07-27) — the two remaining private copies now delegate to BusinessClock
  as well. Before that there were **four** ways to resolve a branch timezone,
  differing in whether they cached and whether they warned when a branch had no
  timezone.

## Testing rules (the #1091 §4 matrix)

- **Always** use `Carbon::setTestNow()` — no time-dependent test may read the
  real `now()`.
- Business assertions run in at least three timezones: `UTC`, `Asia/Tokyo`,
  `Asia/Ho_Chi_Minh`; weekday logic runs across all seven ISO days (1=Mon…7=Sun).
- The canonical "cross-day" instant is `2026-07-25 23:59:59 UTC` (both Tokyo and
  Ho Chi Minh City have already rolled over to the 26th).
- Examples: `tests/Feature/Timezone/BusinessClockTest.php`,
  `tests/Feature/Timezone/BusinessTimezoneContractTest.php`.
- A test that freezes UTC time and touches `getCurrentMenu` or `business_date`
  must **pin `timezone => 'UTC'`** on the branch fixture (the factory defaults to
  `Asia/Tokyo`).

## An OFFLINE sale is dated when it was sold, not when it synced

An order counts at the **moment of sale**, not the moment Cloud receives it.

`insertOrder` defaults `opened_at` to `now()` but honours a caller-supplied
value, and the signed offline replay path passes `evidence.issued_at` — the
instant the device signed. Because of that, an order sold at 20:00 and synced at
09:00 the next morning keeps its real time and lands on the correct business day.
And because that instant is **inside** the signature, a device **cannot backdate**
an order and keep the signature valid (there is a pinning test).

## The Go workstation has its own clock — and that is correct

The workstation runs on **the shop's own machine**, so `time.Now()` there is the
shop's wall clock. Two consequences:

- **Per-day counters** (order numbers, kitchen ticket numbers) key on the LOCAL
  date and reset at the shop's own midnight. That is right — and safe, because
  they are only ever compared against keys written the same way.
- **Queries must NOT compare a local date string against a stored timestamp.**
  Rows store RFC3339 **UTC**, so `date(opened_at) = '<local today>'` is off by
  exactly the shop's offset — nine hours in Tokyo — and during those hours the
  cashier's own dashboard and daily report show the wrong day.

Use `businessDayRangeUTC(date)` (`internal/handler/business_day.go`): it turns one
of the shop's calendar days into a **half-open UTC instant range** `[start, end)`,
so an order placed exactly at midnight belongs to only one day. It uses `AddDate`
rather than `+24h`, so it stays correct across DST changes (where a local day is
23 or 25 hours long).

## Forbidden

- **Do not set `APP_TIMEZONE=Asia/Tokyo`.** One global timezone cannot serve VN
  and JP branches at the same time; it only moves the error from one country to
  the other.
- **Do not store wall-clock strings** in datetime columns. Store the instant
  (UTC) and convert on read.
- **Do not use the DB's clock** for a business day (`CURDATE()`, `CURRENT_DATE`,
  `CURRENT_TIME`, `whereDate('col', now())`): the DB session runs its own
  timezone and `Carbon::setTestNow()` cannot reach it — so it is both wrong and
  untestable.
- **Do not read `SetTimezone::ATTRIBUTE` in business logic.** That is the
  viewer's display timezone.
- **Do not compare an ISO-8601 string against a datetime column.** Pass a
  `Carbon`/`DateTimeInterface` and let the driver bind it, or format it as
  `Y-m-d H:i:s`. `->toIso8601String()` reaching a `where()` is the tell.

  On SQLite — **the engine the test suite runs on** — both sides are strings, so
  the comparison is character by character, and character 11 decides it before
  the clock is ever read:

  ```
  column :  '2026-08-12 21:56:13'
  bound  :  '2026-08-12T21:51:13+00:00'
                       ↑ space (0x20) < 'T' (0x54)
  ```

  The result is **not** an empty filter, which would at least be visible. The
  two strings agree through character 10, so the comparison degrades to **date
  granularity**: rows on a different day still sort correctly, and only rows on
  the **same day as the boundary** land on the wrong side.

  | predicate | degrades to | who is misfiled |
  |---|---|---|
  | `created_at >= $iso` | `date(col) > date(bound)` | same-day rows after the boundary are **dropped** |
  | `created_at <= $iso` | `date(col) <= date(bound)` | same-day rows after the boundary are **kept** |

  That is a report off by up to a day, not an empty one — and for a shift
  boundary it is the worst possible shape, because the boundary sits on the same
  day as nearly every order it exists to exclude.

  Measured, not reasoned — and reproducible, because the fixture is here too.
  SQLite in-memory, boundary `2026-08-12 21:51:13`:

  ```sql
  CREATE TABLE t (id INT, created_at TEXT);
  INSERT INTO t VALUES (1, '2026-08-12 21:56:13');   -- same day, AFTER  the boundary
  INSERT INTO t VALUES (2, '2026-08-12 21:40:00');   -- same day, BEFORE the boundary
  INSERT INTO t VALUES (3, '2026-08-11 23:59:00');   -- day before
  INSERT INTO t VALUES (4, '2026-08-13 01:00:00');   -- day after
  ```

  ```
  >= '2026-08-12T21:51:13+00:00'  → [4]        row 1 is LOST — same day, but after the boundary
  >= '2026-08-12 21:51:13'        → [1, 4]     correct
  <= '2026-08-12T21:51:13+00:00'  → [1, 2, 3]  row 1 is wrongly KEPT
  ```

  Rows 3 and 4 come through correctly in every case — that is the point. Only
  row 1, the one sharing a date with the boundary, moves.

  **MySQL does not do this — and that is worse, not better.** MySQL coerces the
  literal to a temporal value before comparing, so the same ISO string gives the
  *right* answer. Measured on this stack's own MySQL 8.0.46: `>=` returns
  `[1, 4]`, identical to the plain form; an explicit `+09:00` moves the boundary
  for real, so the offset is genuinely parsed; a malformed literal is coerced
  with `Warning 1292` rather than falling back to character comparison. (Offset
  suffixes only parse from MySQL 8.0.19 — that one is the manual, not a
  measurement here.)

  So the defect is **engine-dependent**: real under the test engine, silently
  absent on production MySQL. Neither engine alone tells you the query is
  correct, and a green production is not evidence. Bind a `Carbon` and the
  question stops existing. Found in #2696 while moving a shift-boundary query
  behind a port (#2708).

  **Machine-guarded since #2719** — `BusinessTimeArchitectureTest` fails on an
  ISO-producing call inside a `where`-family argument list. Two limits, stated so
  nobody mistakes the guard for full coverage:

  - **Same line only.** The scan pairs the `where` call with the ISO call inside
    its own balanced parentheses. A cutoff passed in as a **method parameter** —
    which is the exact shape the original #2696 bug had — crosses a boundary the
    scanner cannot see. That layer is still held by review plus a negative test.
  - **Direct calls only.** `$iso = $t->toIso8601String();` on one line and
    `->where(..., $iso)` on the next is not matched. Naming the value does not
    make it safe.

## The guard

`tests/Feature/Timezone/BusinessTimeArchitectureTest.php` scans
`app/Services/**` and `app/Http/Controllers/**` and fails when code:

- reduces the server clock to a DAY (`now()->toDateString()`, `Carbon::today()`
  with no timezone, a bare `->whereDate(`) — the grandfather list is now
  **empty**, the debt is fully paid;
- lets the DB decide the date (`CURDATE`, `CURRENT_DATE`, `CURRENT_TIME`);
- uses `SetTimezone::ATTRIBUTE` outside the presentation layer;
- compares an ISO-8601 string against a datetime column — an ISO-producing call
  (`toIso8601String`, `toISOString`, `toAtomString`, `toRfc3339String`,
  `format(DATE_ATOM|'c')`, …) inside the argument list of a `where`-family call
  (#2719). Limits are stated with the `Forbidden` bullet above.

A line that genuinely is not a business decision can be waived with an inline
`#1091-ok` comment **stating why** — which makes the exemption visible at review
time.

**The scan reads CODE ONLY — comments and docblocks are skipped (#1921).** It was
a raw `preg_match_all` over the file until then, so a line explaining *"this used
to be `now()->toDateString()`, see #1091"* counted as a violation: **writing down
a fix you already made was scored as the bug**. The cheapest way past the guard
was therefore to not explain — which is the opposite of what the guard exists
for. `token_get_all` separates the two, the same fix #1822 applied to
`LegacyRemovalReadiness::codePresent()` for the identical defect.

The `#1091-ok` waiver still works: it is a comment, so it never appears in the
code the scan actually reads.

`composer test:timezones` runs the suite under `UTC`, `Asia/Tokyo` and
`Asia/Ho_Chi_Minh` — this class of bug is invisible in a single CI run in the
middle of the day.

## Boundary filters need a NEGATIVE test

A boundary that lets the wrong rows through is **green on every forward
assertion**. The ISO-8601 ban under `Forbidden` is one way to break a time
boundary; it is not the only one, and it only misbehaves on one engine — so the
test matters more than the rule.

| test | result when the boundary leaks |
|---|---|
| "an order before the boundary IS shown" | **passes** — it is still shown |
| "an order created AFTER the boundary is NOT shown" | **fails** — only this one catches it |

So every boundary filter owes a row on the *wrong* side of the line, asserting
it does not come through. Proving that data comes back proves the query ran; it
does not prove anything was excluded.

This is the same shape as two other measurement bugs in the same week: asserting
"a notification row exists" never catches an empty audience produced by a wrong
role slug (#2451, #2456, #2697), and a unit test of a feature flag never catches
that nothing reads the flag (#2695). In all three the passing direction was
silent and only the failing direction measured anything.

## Cron when serving several countries (#1161)

Cron is **only the firing rhythm**. Every business-day decision inside a job must
still resolve per branch through `BusinessClock::timezoneForBranch()`,
`businessDate()` or `utcRangeForBusinessDates()` —
`operations_timezone` is **never** a source of business time.

```php
// config/app.php
'operations_timezone' => env('APP_OPERATIONS_TIMEZONE', env('APP_DEFAULT_BRANCH_TIMEZONE', 'Asia/Tokyo')),
'operations_timezones' => [
    'JP' => env('APP_OPERATIONS_TIMEZONE_JP', 'Asia/Tokyo'),
    'VN' => env('APP_OPERATIONS_TIMEZONE_VN', 'Asia/Ho_Chi_Minh'),
],
```

**Set `APP_OPERATIONS_TIMEZONE` explicitly in `.env`.** Leave it blank and it
falls through to `APP_DEFAULT_BRANCH_TIMEZONE`, so when somebody changes the
default branch timezone **the cron rhythm shifts with it, unintentionally** — two
variables with different purposes should not be tied together by an implicit
fallback.

### Head office is per operating country (#2838)

One backend serves JP and VN organizations simultaneously, so one global
fallback cannot describe both head offices. When a branch has no usable
`branches.timezone`, `BusinessClock` resolves this chain:

1. an explicit domain fallback, when the domain has one;
2. `operations_timezones[organizations.operating_country]`;
3. the global `operations_timezone` for an unmapped country.

The branch's stored timezone always wins before that chain. Countries with
several zones are therefore handled by setting each branch correctly, not by
adding every regional timezone to the country map. Timezone and operating
country are loaded in one batched query, and the process memo is cleared between
queued jobs so an admin edit cannot remain stale until a worker restart.

### Three approaches, and the one in use

| Approach | How | Use when |
|---|---|---|
| **A. One ops zone, the job loops per branch itself** | Cron fires once on HQ time; the job only processes shops whose own timezone says the day has arrived | **in use** — two or three countries with a small offset (VN↔JP differ by 2 hours) |
| B. Hourly tick with a per-branch gate | `hourly()`; each hour asks "which shops are at HH:00 local right now" | When a country differs by more than 4-5 hours and the job must hit an exact local time per shop |
| C. A separate cron per timezone | Generate several schedules | Not recommended — hard to maintain |

The threshold for moving from A to B: **when a country with a large offset is
onboarded**. For VN↔JP, 07:00 Tokyo is 05:00 Hanoi — early, but acceptable. Add
EU or US (7-14 hours apart) and a "start of day" job lands in the middle of the
night there, at which point A stops being correct.

### Telling "wrong day" apart from "wrong send time"

Not every fixed-hour job is affected by the #1091 class of bug. The distinction
is **what the job compares**:

- Comparing an **absolute instant** does not depend on the local calendar and is
  safe. `CouponExpirationScannerJob` is the example: it compares `valid_until`
  against `Carbon::now()` (a 72h/28h window), so coupons never expire on the
  wrong day in any country — only the **notification send time** follows the
  global rhythm.
- Comparing a **business day** (shift boundaries, day-based expiry, day-grouped
  reports) must be per branch, like `AutoExpireMaterialLots`.
- The notification **digest** window is deliberately the **RECIPIENT's** day —
  the one place a *user* clock is correct on purpose: a digest groups what one
  human reads, so it follows that human, not any branch.

### Checklist for onboarding a new country

1. Set `branches.timezone` (IANA) for every branch in that country.
2. Add an `APP_OPERATIONS_TIMEZONE_{COUNTRY}` fallback only when that country has
   a single defined head-office rhythm; otherwise rely on branch timezones.
3. Re-evaluate `APP_OPERATIONS_TIMEZONE`: is the offset from HQ still acceptable,
   or has it reached the threshold for switching to approach B?
4. Run `composer test:timezones`.

## Remaining (tracked in #1091)

- Run the suite in CI under at least two timezones, plus one job frozen at
  23:59 on a Sunday.
- Scheduled jobs (`dailyAt`) declaring their timezone explicitly;
  `ExpiryAlertService` per branch; covering the rest of the §4.3 matrix.
