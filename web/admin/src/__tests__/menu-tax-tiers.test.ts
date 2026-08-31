import { readFileSync } from "node:fs";
import { join } from "node:path";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { menuService } from "@/services/menu-service";

/**
 * #1218 — tax types resolve through six tiers; three of them are edited on the
 * HQ menu-items screen (menu → section → item, each beating the one above it).
 *
 * Slice 3 shipped tier 3 end to end EXCEPT the control: the endpoint, the
 * service call, the hook and the ja/en/vi copy all landed, but no component
 * ever rendered them. Every section then read "inherit from the menu" while the
 * menu had no reachable way to be given a value — a dead tier that looked alive
 * from every angle except the screen. Nothing failed, because a hook with no
 * caller and a translation with no reader are both perfectly valid code.
 *
 * So these tests check the two halves that silence covered: the wire contract,
 * and the fact that the control is actually mounted.
 */

const ITEMS_PAGE = join(
  process.cwd(),
  "src/app/hq/[brandSlug]/menus/[menuId]/items/page.tsx"
);

const SHOP_MENU_PAGE = join(
  process.cwd(),
  "src/app/shop/[shopSlug]/menus/[menuId]/page.tsx"
);

const SECTION_PANEL = join(
  process.cwd(),
  "src/app/shop/[shopSlug]/menus/[menuId]/components/section-panel.tsx"
);

const LOCALES = ["ja", "en", "vi"] as const;

function itemsPageSource(): string {
  return readFileSync(ITEMS_PAGE, "utf8");
}

function shopMenuPageSource(): string {
  return readFileSync(SHOP_MENU_PAGE, "utf8");
}

function sectionPanelSource(): string {
  return readFileSync(SECTION_PANEL, "utf8");
}

describe("menuService — whole-menu tax type on the wire (#1218 tier 3)", () => {
  beforeEach(() => {
    vi.mocked(globalThis.fetch).mockClear();
  });

  function lastCall() {
    return vi.mocked(globalThis.fetch).mock.calls[0] ?? [];
  }

  it("PATCHes the brand-scoped menu tax-type endpoint", async () => {
    await menuService.updateMenuTaxType("betoya", "menu-1", "tax-standard");

    const [url, options] = lastCall();
    expect(String(url)).toContain("/api/v1/hq/betoya/menus/menu-1/tax-type");
    expect(options?.method).toBe("PATCH");
  });

  it("sends the chosen tax type id", async () => {
    await menuService.updateMenuTaxType("betoya", "menu-1", "tax-reduced");

    const body = JSON.parse(String(lastCall()[1]?.body)) as Record<string, unknown>;
    expect(body).toMatchObject({ tax_type_id: "tax-reduced" });
  });

  it("sends null rather than omitting the key — clearing the tier must stick", async () => {
    await menuService.updateMenuTaxType("betoya", "menu-1", null);

    const body = JSON.parse(String(lastCall()[1]?.body)) as Record<string, unknown>;
    // An omitted key would leave the column untouched server-side, making
    // "back to inherit" silently impossible — the same trap #1187 hit with
    // is_featured.
    expect("tax_type_id" in body).toBe(true);
    expect(body.tax_type_id).toBeNull();
  });
});

describe("menu-items screen mounts all three editable tiers (#1218)", () => {
  it.each([
    ["MenuTaxSelect", "useUpdateMenuTaxType"],
    ["MenuSectionTaxSelect", "useUpdateMenuSectionTaxType"],
    ["MenuItemTaxSelect", "useUpdateMenuProductTaxType"],
  ])("renders %s and wires %s", (component, hook) => {
    const source = itemsPageSource();

    expect(source).toContain(`function ${component}(`);
    // Defined is not mounted. `<Name` only appears where it is rendered, so
    // this is the assertion the missing tier would have failed.
    expect(source).toContain(`<${component}`);
    expect(source).toContain(hook);
  });
});

describe("no dead tax copy (#1218)", () => {
  // The gap was visible in the locale files long before anyone noticed the
  // screen: `label` and `hint` were translated into three languages and read by
  // nobody. Unused copy is the cheapest available signal that a control was
  // designed and then dropped.
  it.each(["hq.menus.tax.label", "hq.menus.tax.aria", "hq.menus.tax.inherit", "hq.menus.tax.hint"])(
    "%s is rendered by the screen",
    (key) => {
      expect(itemsPageSource()).toContain(key);
    }
  );

  it.each(LOCALES)("%s carries every whole-menu tax key", (locale) => {
    const messages = JSON.parse(
      readFileSync(join(process.cwd(), `src/i18n/${locale}.json`), "utf8")
    ) as Record<string, string>;

    for (const key of [
      "hq.menus.tax.label",
      "hq.menus.tax.aria",
      "hq.menus.tax.inherit",
      "hq.menus.tax.hint",
      "hq.menus.tax.toast_saved",
      "hq.menus.tax.toast_cleared",
      "hq.menus.tax.toast_error",
    ]) {
      expect(messages[key]?.trim()).toBeTruthy();
    }
  });
});

/**
 * #1227 — the shop is served the menu HQ synced down, and may not edit tax
 * (#1226). Both HQ-owned tiers were rendered only when they held a value, so a
 * shop looking at an untaxed menu saw nothing at all and could not tell the
 * tier apart from a tier that was never delivered. Render both states.
 *
 * The unset chip must carry NO rate. Lines inside one section legitimately sit
 * at different rates (a real 人形町 section holds 8% and 10% together), so any
 * single number on the tier would misreport what the customer pays.
 */
describe("shop menu shows both HQ-owned tiers, set or not (#1227)", () => {
  it("renders the whole-menu tier in both states", () => {
    const source = shopMenuPageSource();

    expect(source).toContain("shop.menu.detail.tax_menu_badge");
    expect(source).toContain("shop.menu.detail.tax_menu_inherit");
    // The guard that hid the chip outright — its return would restore the bug.
    expect(source).not.toContain("menu.tax_rate != null && (");
  });

  it("renders the section tier in both states", () => {
    const source = sectionPanelSource();

    expect(source).toContain("shop.menu.detail.tax_section_badge");
    expect(source).toContain("shop.menu.detail.tax_section_inherit");
    expect(source).not.toContain("taxRate != null && (");
  });

  it.each(LOCALES)("%s carries the shop-side tier copy", (locale) => {
    const messages = JSON.parse(
      readFileSync(join(process.cwd(), `src/i18n/${locale}.json`), "utf8")
    ) as Record<string, string>;

    for (const key of [
      "shop.menu.detail.tax_menu_badge",
      "shop.menu.detail.tax_menu_inherit",
      "shop.menu.detail.tax_section_badge",
      "shop.menu.detail.tax_section_inherit",
      "shop.menu.detail.tax_readonly_hint",
      // The HQ-side guard copy (#1227 follow-up) — a missing key here renders
      // the raw key to the operator at exactly the moment they are being told
      // their edit will not stick.
      "hq.menus.tax.hq_managed_hint",
    ]) {
      expect(messages[key], `${locale} is missing ${key}`).toBeTruthy();
    }
  });

  /**
   * The shop is reading back a decision HQ made, so the unset state must be
   * NAMED the way HQ names it. It first shipped as "not set", which reads as a
   * different state from HQ's "inherit (menu)" and immediately drew the question
   * of whether the shop had lost the value. Keep the two in lockstep.
   */
  it.each(LOCALES)("%s names the unset tier the way HQ names it", (locale) => {
    const messages = JSON.parse(
      readFileSync(join(process.cwd(), `src/i18n/${locale}.json`), "utf8")
    ) as Record<string, string>;

    expect(messages["shop.menu.detail.tax_section_inherit"]).toContain(
      messages["hq.menus.sections.tax.inherit"]
    );
    expect(messages["shop.menu.detail.tax_menu_inherit"]).toContain(
      messages["hq.menus.tax.inherit"]
    );
  });

  it("states no rate on either unset chip", () => {
    const messages = JSON.parse(
      readFileSync(join(process.cwd(), "src/i18n/en.json"), "utf8")
    ) as Record<string, string>;

    expect(messages["shop.menu.detail.tax_menu_inherit"]).not.toContain("{rate}");
    expect(messages["shop.menu.detail.tax_section_inherit"]).not.toContain("{rate}");
  });
});
