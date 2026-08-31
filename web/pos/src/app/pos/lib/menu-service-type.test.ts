import { describe, expect, it } from "vitest";
import {
  MENU_SERVICE_TYPE_BADGE_CLASS,
  MENU_SERVICE_TYPE_LABEL_KEY,
  isAutoSelectableMenu,
  orderTypeToServiceType,
  resolveMenuServiceType,
} from "./menu-service-type";
import type { CustomerOrderType, MenuServiceType } from "@/app/pos/types";
import ja from "@/i18n/ja.json";
import en from "@/i18n/en.json";
import vi from "@/i18n/vi.json";

describe("orderTypeToServiceType (#481, #1745)", () => {
  it("maps dine_in → DineIn", () => {
    expect(orderTypeToServiceType("dine_in")).toBe("DineIn");
  });

  it("maps takeaway → Takeaway", () => {
    expect(orderTypeToServiceType("takeaway")).toBe("Takeaway");
  });

  // #1765 reverses #1745's answer here on purpose: a counter sale is sold both
  // ways, so the cashier reaches both lists. The money guard that #1745 was
  // really protecting moved to isAutoSelectableMenu — see the suite below,
  // which is what must stay green for that protection to still exist.
  it("leaves spot ungated so both lists are reachable (#1765)", () => {
    expect(orderTypeToServiceType("spot")).toBeUndefined();
  });

  it("maps null/undefined (no active order) → undefined", () => {
    expect(orderTypeToServiceType(null)).toBeUndefined();
    expect(orderTypeToServiceType(undefined)).toBeUndefined();
  });

  it("keeps dine_in and takeaway gated — those types already state intent", () => {
    expect(orderTypeToServiceType("dine_in")).toBe("DineIn");
    expect(orderTypeToServiceType("takeaway")).toBe("Takeaway");
    expect(orderTypeToServiceType("dine_in")).not.toBe(
      orderTypeToServiceType("takeaway"),
    );
  });
});

describe("isAutoSelectableMenu (#1765)", () => {
  const dineIn = { service_type: "DineIn" as const };
  const takeaway = { service_type: "Takeaway" as const };
  const both = { service_type: "Both" as const };

  // The regression this suite exists to stop, inherited from #1745. `spot` is
  // the dialog's default AND the column default, and MenuCatalog auto-picks by
  // time window with a menus[0] fallback — so with spot ungated, an untouched
  // POS could ring a counter sale against a Takeaway menu. The 8% that menu
  // carries snapshots onto the order line, and no later order-type edit undoes
  // it, because TaxResolver never reads order_type (plan-043 / #1099).
  it("never lets the app auto-pick a Takeaway menu for a spot order", () => {
    expect(isAutoSelectableMenu("spot", takeaway)).toBe(false);
  });

  it("allows DineIn and Both for a spot order", () => {
    expect(isAutoSelectableMenu("spot", dineIn)).toBe(true);
    expect(isAutoSelectableMenu("spot", both)).toBe(true);
  });

  it("reads the Cloud shape, where the raw column means 'inherit'", () => {
    expect(
      isAutoSelectableMenu("spot", {
        service_type: null,
        effective_service_type: "Takeaway",
      }),
    ).toBe(false);
  });

  // An old backend/workstation states no service type at all. Excluding those
  // would leave NOTHING auto-selectable, and MenuCatalog's product grid stays
  // locked until a menu is chosen — a tax guard that turns into an outage.
  it("treats an unstated service type as selectable", () => {
    expect(isAutoSelectableMenu("spot", {})).toBe(true);
    expect(isAutoSelectableMenu("spot", { service_type: null })).toBe(true);
  });

  // dine_in / takeaway are gated at the LIST level, so everything reaching the
  // picker is already the right type — a second opinion here could only
  // disagree with the server.
  it("does not second-guess the already-gated order types", () => {
    const gated: CustomerOrderType[] = ["dine_in", "takeaway"];

    for (const orderType of gated) {
      for (const menu of [dineIn, takeaway, both]) {
        expect(isAutoSelectableMenu(orderType, menu)).toBe(true);
      }
    }
  });

  it("does not gate when there is no active order", () => {
    expect(isAutoSelectableMenu(null, takeaway)).toBe(true);
    expect(isAutoSelectableMenu(undefined, takeaway)).toBe(true);
  });
});

describe("resolveMenuServiceType (#1756)", () => {
  it("prefers Cloud's effective_service_type over the raw column", () => {
    // The Cloud shape for an INHERITING branch menu: own value NULL (= "ask
    // the master"), resolved value alongside it. Reading the raw column here
    // would render nothing for the very menus HQ configured centrally.
    expect(
      resolveMenuServiceType({
        service_type: null,
        effective_service_type: "Takeaway",
      }),
    ).toBe("Takeaway");
  });

  it("falls back to service_type — the workstation LAN shape", () => {
    // The mirror stores the ALREADY-resolved value (Cloud's
    // MenuCatalogReplicaBuilder collapses own ?? master ?? Both before it
    // syncs down), and sends no effective_service_type at all.
    expect(resolveMenuServiceType({ service_type: "DineIn" })).toBe("DineIn");
  });

  it("keeps an own value that overrides the master", () => {
    expect(
      resolveMenuServiceType({
        service_type: "DineIn",
        effective_service_type: "DineIn",
      }),
    ).toBe("DineIn");
  });

  it("returns undefined when the server stated nothing", () => {
    // A backend or workstation older than #1756. The badge must then be
    // ABSENT, never defaulted: the tax rate rides the menu line the item is
    // added from, so a guessed "Both" is an unfounded 8%-vs-10% claim on the
    // one screen where that costs money.
    expect(resolveMenuServiceType({})).toBeUndefined();
    expect(resolveMenuServiceType({ service_type: null })).toBeUndefined();
    expect(
      resolveMenuServiceType({
        service_type: null,
        effective_service_type: null,
      }),
    ).toBeUndefined();
  });

  it("covers every MenuServiceType with a label and a badge colour", () => {
    const all: MenuServiceType[] = ["DineIn", "Takeaway", "Both"];
    const catalogues = { ja, en, vi } as Record<
      string,
      Record<string, string>
    >;

    for (const serviceType of all) {
      const key = MENU_SERVICE_TYPE_LABEL_KEY[serviceType];
      expect(key, `${serviceType} needs a label key`).toBeTruthy();
      expect(
        MENU_SERVICE_TYPE_BADGE_CLASS[serviceType],
        `${serviceType} needs badge classes`,
      ).toBeTruthy();

      // A dot-path rendered raw to a cashier is the failure mode the i18n
      // catalogue test exists for; these keys are reached through a map, not a
      // t("literal") call, so its scan cannot see them.
      for (const [locale, catalogue] of Object.entries(catalogues)) {
        expect(catalogue[key], `${locale} is missing ${key}`).toBeTruthy();
      }
    }
  });

  it("keeps DineIn and Takeaway visually distinct", () => {
    expect(MENU_SERVICE_TYPE_BADGE_CLASS.DineIn).not.toBe(
      MENU_SERVICE_TYPE_BADGE_CLASS.Takeaway,
    );
    expect(MENU_SERVICE_TYPE_LABEL_KEY.DineIn).not.toBe(
      MENU_SERVICE_TYPE_LABEL_KEY.Takeaway,
    );
  });
});
