/**
 * Auto-selection of the POS menu, lifted out of `MenuCatalog` by #1765.
 *
 * It moved because it stopped being a display convenience: what the app picks
 * when nobody chose decides which tax rate an order line snapshots (see
 * `isAutoSelectableMenu`). That belongs somewhere a test can reach without
 * mounting the whole catalogue.
 */

/** "HH:MM[:SS]" → minutes-since-midnight, or null if unparseable. */
export function parseScheduleTime(t: string | null | undefined): number | null {
  if (!t) return null;
  const m = /^(\d{1,2}):(\d{2})(?::\d{2})?$/.exec(t);
  if (!m) return null;
  return parseInt(m[1], 10) * 60 + parseInt(m[2], 10);
}

/**
 * Pick the menu whose schedule window covers `now` from the day-filtered
 * list. The by-day endpoint already filters to the current day-of-week,
 * so we only have to match the time-of-day. Falls back to the first menu
 * when nothing matches (e.g. staff opens the POS before any window starts).
 *
 * Handles wrap-around schedules where end <= start (a window that crosses
 * midnight, e.g. "22:00 – 02:00") — current time is "in" the window when
 * it's at-or-after the start OR before the end.
 *
 * #1765 — `eligible` narrows what may be picked WITHOUT the cashier asking.
 * A `spot` order lists both service types, so the auto-pick must not be
 * allowed to land on a Takeaway menu (see `isAutoSelectableMenu`). When the
 * predicate rules out every menu — an old feed that states no service type at
 * all — it is ignored rather than obeyed: locking the product grid shut is a
 * worse failure than the one the predicate guards against.
 */
export function pickActiveMenu<
  T extends { start_time: string; end_time: string },
>(menus: T[], now: Date, eligible?: (menu: T) => boolean): T | undefined {
  if (menus.length === 0) return undefined;

  const preferred = eligible ? menus.filter(eligible) : menus;
  const pool = preferred.length > 0 ? preferred : menus;

  const minutes = now.getHours() * 60 + now.getMinutes();
  const match = pool.find((m) => {
    const start = parseScheduleTime(m.start_time);
    const end = parseScheduleTime(m.end_time);
    if (start === null || end === null) return false;
    if (end <= start) return minutes >= start || minutes < end;
    return minutes >= start && minutes < end;
  });

  return match ?? pool[0];
}
