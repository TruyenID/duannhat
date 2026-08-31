import type { WeeklyHoursMap, WeeklyHoursDay } from "@/data/brands"
import { wallClockAt, weekdayIndexOf } from "./branch-clock.ts"

/**
 * #1160 — "is this pickup slot inside the shop's opening hours?", evaluated on
 * the SHOP's wall clock.
 *
 * Mirrors backend `App\Services\Shop\BranchOpeningHours` deliberately: the
 * server is the gate (a stale tab or a direct API call must still be refused),
 * this copy exists so the customer is told BEFORE submitting instead of losing
 * a filled-in checkout form to a 422.
 *
 * The clock matters. `weekly_hours` is a wall clock at the branch, so a Hanoi
 * customer scheduling a Tokyo pickup must be judged against Tokyo's 22:00, not
 * their own (#1091). We resolve the branch-local Y/M/D h:m via
 * Intl.DateTimeFormat rather than the device's — the same instant, read on the
 * shop's clock.
 *
 * Fail-open when unconfigured: most shops have never filled weekly_hours in,
 * and refusing every scheduled pickup because a JSON column is empty is a
 * worse bug than the one this guards.
 */

const DAY_KEYS = ["sun", "mon", "tue", "wed", "thu", "fri", "sat"] as const

export type DayKey = (typeof DAY_KEYS)[number]

/** Minutes since midnight, or null when the value isn't a usable "HH:mm". */
function parseTimeOfDay(value: string | undefined): number | null {
  if (typeof value !== "string") return null

  const match = /^(\d{1,2}):(\d{2})$/.exec(value.trim())
  if (!match) return null

  const hours = Number(match[1])
  const minutes = Number(match[2])
  if (hours > 23 || minutes > 59) return null

  return hours * 60 + minutes
}

/**
 * The instant read on the branch's clock: weekday + minutes since midnight.
 *
 * godx-tempo#1767 — the Intl plumbing used to live here, inline. It moved to
 * `lib/branch-clock.ts` when the pickup picker needed the SAME clock (and the
 * reverse conversion). Two hand-rolled definitions of "the branch's clock" is
 * precisely the shape of the bug that issue reports, so there is now one.
 * Behaviour is unchanged, including the fall back to the device clock for a
 * missing or unparsable zone.
 */
function atBranch(date: Date, timeZone?: string | null): { dayIndex: number; minuteOfDay: number } {
  const wall = wallClockAt(date, timeZone)

  return { dayIndex: weekdayIndexOf(wall), minuteOfDay: wall.hour * 60 + wall.minute }
}

function entryFor(weeklyHours: WeeklyHoursMap, dayIndex: number): WeeklyHoursDay | undefined {
  return weeklyHours[DAY_KEYS[((dayIndex % 7) + 7) % 7]]
}

/**
 * The window that STARTS on the given weekday, in minutes since that day's
 * midnight. `close <= open` reads as overnight, so close can exceed 1440.
 */
function windowStartingOn(
  weeklyHours: WeeklyHoursMap,
  dayIndex: number,
): { open: number; close: number } | null {
  const entry = entryFor(weeklyHours, dayIndex)
  if (!entry || entry.closed === true) return null

  const open = parseTimeOfDay(entry.open)
  let close = parseTimeOfDay(entry.close)
  if (open === null || close === null) return null

  if (close <= open) close += 24 * 60

  return { open, close }
}

export function hasPublishedHours(weeklyHours: WeeklyHoursMap | null | undefined): boolean {
  return !!weeklyHours && Object.keys(weeklyHours).length > 0
}

export function isOpenAt(
  weeklyHours: WeeklyHoursMap | null | undefined,
  date: Date,
  timeZone?: string | null,
): boolean {
  if (!hasPublishedHours(weeklyHours)) return true

  const hours = weeklyHours as WeeklyHoursMap
  const { dayIndex, minuteOfDay } = atBranch(date, timeZone)

  // An overnight window that opened YESTERDAY still covers an after-midnight
  // slot, whatever today's own entry says.
  const overnight = windowStartingOn(hours, dayIndex - 1)
  if (overnight && minuteOfDay + 24 * 60 >= overnight.open && minuteOfDay + 24 * 60 <= overnight.close) {
    return true
  }

  const entry = entryFor(hours, dayIndex)
  // Day absent from the schedule — not a constraint.
  if (!entry) return true
  // The one explicit "we are shut" signal.
  if (entry.closed === true) return false

  const window = windowStartingOn(hours, dayIndex)
  // Published but unusable (missing/malformed times) — don't invent a
  // constraint out of a broken row.
  if (!window) return true

  return minuteOfDay >= window.open && minuteOfDay <= window.close
}

/** Minutes since midnight → "HH:mm". */
function formatTimeOfDay(minutes: number): string {
  const h = Math.floor(minutes / 60) % 24
  const m = minutes % 60
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`
}

export interface NextOpening {
  /** Branch-local days from the given instant: 0 = today, 1 = tomorrow, … */
  dayOffset: number
  /** Weekday the shop opens on, for the "reopens Monday" case. */
  day: DayKey
  /** Opening time, "HH:mm" on the branch's clock. */
  time: string
}

/**
 * #1167 — when the shop is shut, when does it open next? Mirrors backend
 * `BranchOpeningHours::nextOpeningAt` so the notice we show matches the
 * `opens_at` the API would quote back on a refused order.
 *
 * Scans today (a shop shut at 05:00 opens at 06:00 the SAME day) and the
 * following week — enough to wrap around for a shop open one weekday only.
 * Null when the branch publishes no usable schedule.
 */
export function nextOpening(
  weeklyHours: WeeklyHoursMap | null | undefined,
  date: Date,
  timeZone?: string | null,
): NextOpening | null {
  if (!hasPublishedHours(weeklyHours)) return null

  const hours = weeklyHours as WeeklyHoursMap
  const { dayIndex, minuteOfDay } = atBranch(date, timeZone)

  for (let offset = 0; offset <= 7; offset++) {
    const window = windowStartingOn(hours, dayIndex + offset)
    // Today's window only counts if it hasn't opened yet; one already past is
    // what put us here.
    if (!window || (offset === 0 && window.open <= minuteOfDay)) continue

    return {
      dayOffset: offset,
      day: DAY_KEYS[((dayIndex + offset) % 7 + 7) % 7],
      time: formatTimeOfDay(window.open),
    }
  }

  return null
}

/**
 * The closing time ("HH:mm", branch-local) of the day the instant lands on —
 * what we quote back to the customer. Null when unconfigured or closed all day.
 */
export function closingTimeLabel(
  weeklyHours: WeeklyHoursMap | null | undefined,
  date: Date,
  timeZone?: string | null,
): string | null {
  if (!hasPublishedHours(weeklyHours)) return null

  const hours = weeklyHours as WeeklyHoursMap
  const { dayIndex } = atBranch(date, timeZone)
  const entry = entryFor(hours, dayIndex)

  if (!entry || entry.closed === true) return null

  return parseTimeOfDay(entry.close) === null ? null : (entry.close as string)
}
