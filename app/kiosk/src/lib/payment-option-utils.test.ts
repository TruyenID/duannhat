import { readFileSync } from "node:fs";
import { existsSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";

import {
  effectiveOptionsToTiles,
  isRouteAllowed,
} from "../lib/payment-option-utils";
import type { EffectivePaymentOptionsSnapshot } from "../types/effective-payment-options";

const KIOSK_SRC = join(import.meta.dirname, "..", "..");

function readKioskPaymentOptionSources(): string {
  const targets = [
    join(KIOSK_SRC, "components", "ui", "payment-options-panel.tsx"),
    join(KIOSK_SRC, "hooks", "use-effective-payment-options.ts"),
    join(KIOSK_SRC, "lib", "payment-option-utils.ts"),
    join(KIOSK_SRC, "..", "app", "payment-method.tsx"),
  ];

  return targets
    .filter((path) => existsSync(path))
    .map((path) => readFileSync(path, "utf8"))
    .join("\n");
}

describe("F7 kiosk effective options — resolver-driven tiles", () => {
  it("F7 renders only effective options returned by the snapshot", () => {
    const snapshot: EffectivePaymentOptionsSnapshot = {
      revision: 3,
      snapshot_hash: "hash-3",
      ownership_revision: "own-1",
      published_at: "2026-07-28T00:00:00Z",
      options: [
        {
          // #1202 — this fixture used to carry `route` and `client`, neither of
          // which exists on EffectivePaymentOptionRow, and to omit nine fields
          // that do. It compiled nowhere; vitest transpiles rather than
          // typechecks, so it ran green anyway.
          //
          // Dropping `route` costs the test nothing — `effectiveOptionsToTiles`
          // DERIVES the route from `rail` via routeForRail(), so the assertion
          // below already proves wallet → qr rather than echoing an input.
          id: "opt-paypay",
          display_name: "PayPay",
          provider: "paypay",
          rail: "wallet",
          effective: true,
          source: "shop",
          reason: "enabled",
          error_code: null,
          connection_id: "conn-1",
          connection_option_id: "conn-opt-1",
          shop_option_id: "shop-opt-1",
          owner_scope: "shop",
          shop_preference: "enabled",
          device_preference: "inherit",
          trace: [],
        },
      ],
    };

    const tiles = effectiveOptionsToTiles(snapshot.options);
    expect(tiles).toHaveLength(1);
    expect(tiles[0]?.route).toBe("qr");
    expect(isRouteAllowed("qr", tiles)).toBe(true);
    expect(isRouteAllowed("cash", tiles)).toBe(false);
  });

  it("F7 payment option picker sources avoid fixed cash/card/qr/emoney lists", () => {
    const sources = readKioskPaymentOptionSources();
    expect(sources).not.toMatch(
      /\[\s*['"]cash['"]\s*,\s*['"]card['"]\s*,\s*['"]qr['"]\s*,\s*['"]emoney['"]\s*\]/,
    );
    expect(sources).not.toMatch(/HARDCODED_PAYMENT_METHODS/);
  });
});
