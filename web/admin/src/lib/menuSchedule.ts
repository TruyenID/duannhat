/**
 * Bitmask helpers for MenuSchedule.days_of_week.
 *
 * Bit mapping matches PHP Carbon dayOfWeek (0-indexed from Sunday):
 *   bit0=Sun (1), bit1=Mon (2), bit2=Tue (4), bit3=Wed (8),
 *   bit4=Thu (16), bit5=Fri (32), bit6=Sat (64)
 *
 * This is identical to PHP: `1 << now()->dayOfWeek` and
 * MySQL: `1 << (DAYOFWEEK(NOW()) - 1)` — all three produce the same bit position.
 */

const DAY_LABELS = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"] as const;
export type DayLabel = (typeof DAY_LABELS)[number];

/** Decode a days_of_week bitmask to an array of day labels. */
export function daysOfWeekToLabels(bitmask: number): DayLabel[] {
  return DAY_LABELS.filter((_, bit) => (bitmask >> bit) & 1);
}

/** Encode an array of day labels to a days_of_week bitmask. */
export function labelsToDaysOfWeek(labels: string[]): number {
  return labels.reduce((acc, label) => {
    const bit = DAY_LABELS.indexOf(label as DayLabel);
    return bit >= 0 ? acc | (1 << bit) : acc;
  }, 0);
}

/**
 * Convert "HH:MM:SS" (API format) to "HH:MM" (TimePicker format).
 * Passes through strings already in "HH:MM" format.
 */
export function toHHMM(time: string): string {
  return time.length > 5 ? time.slice(0, 5) : time;
}

/**
 * Convert "HH:MM" (TimePicker format) to "HH:MM:SS" (API format).
 * Passes through strings already in "HH:MM:SS" format.
 */
export function toHHMMSS(time: string): string {
  return time.length === 5 ? `${time}:00` : time;
}

// =========================================================================
//  Form helpers (colocated with bitmask helpers to enable unit testing)
// =========================================================================

export interface ScheduleSubmitPayload {
  start_time: string;
  end_time: string;
  days_of_week: number;
  is_active: boolean;
  // Optional calendar-date bounds (TC-MSCH-103). null clears the bound.
  start_date: string | null;
  end_date: string | null;
  /** #1979 — one kind per row; the columns of the other kinds are left alone. */
  recurrence_kind?: "Weekly" | "Monthly" | "SpecificDates";
  days_of_month?: number;
  specific_dates?: string[];
}

/** Convert schedule form values to the API POST/PUT payload shape. */
export function formValuesToPayload(values: {
  start_time: string;
  end_time: string;
  days: string[];
  is_active: boolean;
  start_date?: string;
  end_date?: string;
  recurrence_kind?: "Weekly" | "Monthly" | "SpecificDates";
  days_of_month?: number[];
  specific_dates?: string[];
}): ScheduleSubmitPayload {
  const kind = values.recurrence_kind ?? "Weekly";

  return {
    start_time: toHHMMSS(values.start_time),
    end_time: toHHMMSS(values.end_time),
    // A non-weekly row still sends a weekday mask: the API requires one only
    // for Weekly, but every OTHER reader (overlap warning, the shop's effective
    // view) reads the column unconditionally, and 0 there would render as "no
    // days" — a row that looks broken while working fine.
    days_of_week: kind === "Weekly" ? labelsToDaysOfWeek(values.days) : 127,
    is_active: values.is_active,
    // Empty input → null (unbounded), so the API stores no bound.
    start_date: values.start_date?.trim() ? values.start_date : null,
    end_date: values.end_date?.trim() ? values.end_date : null,
    recurrence_kind: kind,
    // Only the column belonging to THIS kind is sent. The others keep whatever
    // they held, so switching kind back and forth does not destroy what the
    // user typed (BR-MSD03 on the API side says the same).
    ...(kind === "Monthly"
      ? { days_of_month: (values.days_of_month ?? []).reduce((mask, d) => mask | (1 << (d - 1)), 0) }
      : {}),
    ...(kind === "SpecificDates" ? { specific_dates: values.specific_dates ?? [] } : {}),
  };
}

// =========================================================================
//  Overlap detection (TC-MSCH-106)
// =========================================================================

/** Minimal shape an overlap check needs — both the API row and the form payload satisfy it. */
export interface ScheduleWindow {
  start_time: string;
  end_time: string;
  days_of_week: number;
}

/**
 * Whether two schedule windows overlap: they must share at least one
 * day-of-week bit AND their time ranges must intersect. Time ranges are
 * half-open [start, end) — a window ending exactly when another starts does
 * not overlap. Times are normalized to HH:MM:SS so fixed-width lexicographic
 * comparison is correct. Windows never wrap past midnight (end > start is
 * enforced by the form), so same-day comparison is sufficient.
 */
export function schedulesOverlap(a: ScheduleWindow, b: ScheduleWindow): boolean {
  if ((a.days_of_week & b.days_of_week) === 0) {
    return false;
  }

  const aStart = toHHMMSS(a.start_time);
  const aEnd = toHHMMSS(a.end_time);
  const bStart = toHHMMSS(b.start_time);
  const bEnd = toHHMMSS(b.end_time);

  return aStart < bEnd && bStart < aEnd;
}

/**
 * Return every existing schedule that overlaps the candidate window, skipping
 * the row identified by `excludeId` (the schedule being edited). Used to warn
 * before creating/updating an overlapping schedule (TC-MSCH-106).
 */
export function findOverlappingSchedules<T extends ScheduleWindow & { id: string }>(
  candidate: ScheduleWindow,
  existing: readonly T[],
  excludeId?: string
): T[] {
  return existing.filter((s) => s.id !== excludeId && schedulesOverlap(candidate, s));
}
