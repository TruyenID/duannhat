import { describe, expect, it } from "vitest";
import { renderHook } from "@testing-library/react";
import {
  ShopTimezoneProvider,
  useShopTimezone,
  useShopTimezoneLabel,
} from "@/providers/shop-timezone-provider";
import { formatDateTime } from "@/lib/date";

/**
 * #1248 — a shop-scoped screen showed `business_date` (the SHOP's day, computed
 * by BusinessClock on the backend) beside `opened_at` rendered in the BROWSER's
 * zone. A manager in Hanoi reading a Tokyo shift saw 2026-07-30 next to
 * 22:00 on 2026-07-29: both correct, silently contradicting each other.
 */

function wrapper(timezone: string | null | undefined) {
  return function Wrapper({ children }: { children: React.ReactNode }) {
    return <ShopTimezoneProvider timezone={timezone}>{children}</ShopTimezoneProvider>;
  };
}

describe("useShopTimezone", () => {
  it("hands the shop's zone to the screens inside it", () => {
    const { result } = renderHook(() => useShopTimezone(), { wrapper: wrapper("Asia/Tokyo") });
    expect(result.current).toBe("Asia/Tokyo");
  });

  it("is null outside a shop route, so formatting falls back to the browser", () => {
    // Not an error case: HQ screens and /inbox are about the viewer, and the
    // viewer's own zone is the right answer there.
    const { result } = renderHook(() => useShopTimezone());
    expect(result.current).toBeNull();
  });

  it("treats an empty or blank zone as absent rather than passing it to Intl", () => {
    // Intl.DateTimeFormat throws on timeZone: "". A shop row with a blank
    // timezone column must degrade to the browser's zone, not crash a money
    // screen.
    for (const blank of ["", "   ", null, undefined]) {
      const { result } = renderHook(() => useShopTimezone(), { wrapper: wrapper(blank) });
      expect(result.current).toBeNull();
    }
  });
});

describe("the shift timestamp a Hanoi manager sees", () => {
  it("is the shop's local time, not the reader's", () => {
    // 2026-07-29T22:00+07:00 in Hanoi is 2026-07-30T00:00 in Tokyo — the exact
    // window where the two numbers on the screen disagreed. Business date said
    // the 30th because the shift belongs to Tokyo's day; the open time said the
    // 29th because it was drawn in the reader's zone.
    const openedAt = "2026-07-29T15:00:00Z";

    expect(formatDateTime(openedAt, "en", "Asia/Tokyo")).toContain("07/30");
    expect(formatDateTime(openedAt, "en", "Asia/Ho_Chi_Minh")).toContain("07/29");
  });

  it("still renders when the shop's zone is unknown", () => {
    expect(formatDateTime("2026-07-29T15:00:00Z", "en", null)).not.toBe("—");
  });
});

describe("useShopTimezoneLabel", () => {
  it("labels the clock when it is not the reader's", () => {
    // Asserted across several shop zones, minus whichever one the machine
    // running the test happens to be in. Hardcoding "Asia/Tokyo" made this pass
    // under TZ=Asia/Ho_Chi_Minh and fail under TZ=Asia/Tokyo — where the code
    // was RIGHT to stay silent, and only the test was wrong. The suite has to
    // survive both, because the developers do.
    const viewerZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const shopZones = ["Asia/Tokyo", "Asia/Ho_Chi_Minh", "America/New_York"].filter(
      (z) => z !== viewerZone
    );

    expect(shopZones.length).toBeGreaterThan(0);

    for (const zone of shopZones) {
      const { result } = renderHook(() => useShopTimezoneLabel("en"), {
        wrapper: wrapper(zone),
      });

      // Asserting the shape rather than an exact string: the point is that the
      // reader is TOLD which clock this is, not that ICU spells it a given way.
      expect(result.current).toMatch(/GMT/);
    }
  });

  it("says nothing when the shop's clock IS the reader's", () => {
    // A Tokyo manager reading a Tokyo shift needs no explanation, and a label
    // on every timestamp would be noise on the screens people use all day.
    const viewerZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const { result } = renderHook(() => useShopTimezoneLabel("en"), {
      wrapper: wrapper(viewerZone),
    });

    expect(result.current).toBeNull();
  });

  it("says nothing rather than throwing on a malformed zone", () => {
    const { result } = renderHook(() => useShopTimezoneLabel("en"), {
      wrapper: wrapper("Not/AZone"),
    });

    expect(result.current).toBeNull();
  });
});
