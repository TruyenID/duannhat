"use client";

import { createContext, useContext, useMemo } from "react";

/**
 * The timezone a shop-scoped screen must render its timestamps in (#1248).
 *
 * `useTimezone()` in app-provider is the VIEWER's preference, defaulting to the
 * browser's zone. That is the right answer for screens about the viewer, and the
 * wrong one for screens about a shop: a cashier shift belongs to the shop that
 * ran it, in the country it ran in.
 *
 * The symptom this exists to remove: on one till-session screen,
 * `business_date` printed raw (the backend already computes it in the shop's
 * zone — #1091) next to `opened_at` rendered in the browser's. A manager in
 * Hanoi opening a Tokyo shift saw business date 2026-07-30 beside an open time
 * of 22:00 on 2026-07-29. Both numbers were correct; nothing on the screen
 * explained why they disagreed.
 *
 * The project rule this follows, from the umbrella CLAUDE.md: "a Hanoi manager
 * opening the Tokyo Z-report must see Tokyo's business day."
 *
 * Nothing here reaches business LOGIC. Business time is computed on the backend
 * through BusinessClock; this is presentation only, deciding which zone an
 * instant is drawn in.
 */
const ShopTimezoneContext = createContext<string | null>(null);

export function ShopTimezoneProvider({
  timezone,
  children,
}: {
  timezone: string | null | undefined;
  children: React.ReactNode;
}) {
  // Normalised to null so a shop row with an empty string behaves like an
  // absent one rather than being handed to Intl, which throws on "".
  const value = useMemo(() => (timezone?.trim() ? timezone.trim() : null), [timezone]);

  return <ShopTimezoneContext.Provider value={value}>{children}</ShopTimezoneContext.Provider>;
}

/**
 * The shop's IANA zone, or null outside a shop route / when the shop has none.
 *
 * Callers pass the result straight to `formatDateTime(iso, locale, tz)`, whose
 * timezone parameter is optional — null falls back to the browser's zone, which
 * is the old behaviour and the only sensible answer when the shop's zone is
 * genuinely unknown.
 */
export function useShopTimezone(): string | null {
  return useContext(ShopTimezoneContext);
}

/**
 * A short zone label to sit beside a rendered timestamp, e.g. "GMT+9".
 *
 * Shown so a reader can tell WHOSE clock they are looking at. Without it,
 * switching these screens to shop time would replace one silently-ambiguous
 * number with a different silently-ambiguous number: the Hanoi manager would
 * now see the right time and still have no way to know it was Tokyo's.
 *
 * Returns null when the zone is unknown (nothing useful to label) or when the
 * shop's zone matches the viewer's, where a label is only noise.
 */
export function useShopTimezoneLabel(locale: string): string | null {
  const timezone = useShopTimezone();

  return useMemo(() => {
    if (!timezone) return null;

    let viewerZone: string | undefined;
    try {
      viewerZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    } catch {
      viewerZone = undefined;
    }
    if (viewerZone === timezone) return null;

    try {
      const parts = new Intl.DateTimeFormat(locale, {
        timeZone: timezone,
        timeZoneName: "shortOffset",
      }).formatToParts(new Date());

      return parts.find((p) => p.type === "timeZoneName")?.value ?? null;
    } catch {
      // An unknown or malformed IANA id: the screen still renders, it just
      // carries no label. Better than throwing inside a money screen.
      return null;
    }
  }, [timezone, locale]);
}
