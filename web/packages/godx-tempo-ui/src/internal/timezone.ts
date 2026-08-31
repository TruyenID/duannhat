/**
 * Converts a Date to a "display Date" that shows correct values for the target timezone
 * when passed to date-fns `format()`. Uses native `Intl.DateTimeFormat` — no extra dependencies.
 *
 * The returned Date is NOT a true UTC/epoch-correct Date — it is shifted so that
 * `.getHours()`, `.getMinutes()`, etc. return the values for the target timezone.
 * Use it only for display formatting, never for calculations or persistence.
 */
export function toZonedTime(date: Date, timeZone: string): Date {
  // Format the date's wall-clock representation in the target timezone,
  // then parse it back as a local Date. This makes getHours(), getDate(), etc.
  // return the values for the target timezone — exactly what date-fns format() reads.
  return new Date(date.toLocaleString('en-US', { timeZone }));
}

/**
 * Returns a display Date representing "now" in the given timezone.
 * Shorthand for `toZonedTime(new Date(), timeZone)`.
 */
export function nowInTimezone(timeZone: string): Date {
  return toZonedTime(new Date(), timeZone);
}
