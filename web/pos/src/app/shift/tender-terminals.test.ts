import { describe, expect, it } from "vitest";
import type {
  TillTenderCategoryRow,
  TillTenderType,
} from "@/services/till-service";
import {
  buildTenderBrandGroups,
  buildTerminalSections,
  tenderCategoryLabel,
  tenderDisplayName,
  GENERIC_SECTION_KEY,
  type PaymentTerminal,
} from "./tender-terminals";

function tt(
  tender_key: string,
  category: string,
  is_expected_anchor = false,
  name: TillTenderType["name"] = tender_key,
): TillTenderType {
  return {
    tender_key,
    category,
    is_expected_anchor,
    name,
  } as unknown as TillTenderType;
}

function cat(key: string, name: string): TillTenderCategoryRow {
  return { id: key, key, name, sort_order: 0, is_system: true };
}

// JP vocabulary excerpt (matches config/tender_vocabulary.php JP).
const TENDERS: TillTenderType[] = [
  tt("cash", "cash", true),
  tt("credit", "card", true),
  tt("rakuten_pay", "qr"),
  tt("paypay", "qr"),
  tt("ginko_pay", "qr"), // no vendor template covers 銀行Pay
  tt("wechat_pay", "qr"),
  tt("alipay", "qr"),
  tt("id", "emoney"),
  tt("quicpay", "emoney"),
];

const VISIBLE = ["card", "qr", "emoney"];

// Vendor templates (config/tender_templates.php excerpts).
const STERA: PaymentTerminal = {
  id: "dev-stera",
  name: "Stera terminal 01",
  accepts: ["credit", "paypay", "rakuten_pay", "id", "quicpay"],
};
const STARPAY: PaymentTerminal = {
  id: "dev-starpay",
  name: "StarPay 01",
  accepts: ["credit", "paypay", "wechat_pay", "alipay"],
};

describe("buildTerminalSections", () => {
  it("no terminals → single generic section carrying every non-cash tender (backward compatible)", () => {
    const sections = buildTerminalSections([], TENDERS, VISIBLE);
    expect(sections).toHaveLength(1);
    expect(sections[0].key).toBe(GENERIC_SECTION_KEY);
    expect(sections[0].deviceName).toBeNull();
    expect(sections[0].tenderKeys).toEqual([
      "credit",
      "rakuten_pay",
      "paypay",
      "ginko_pay",
      "wechat_pay",
      "alipay",
      "id",
      "quicpay",
    ]);
    expect(sections[0].ownedCategoryKeys).toEqual(VISIBLE);
  });

  it("two terminals → one section each; overlapping brands go to the FIRST terminal; leftovers → generic", () => {
    const sections = buildTerminalSections([STERA, STARPAY], TENDERS, VISIBLE);
    expect(sections.map((s) => s.key)).toEqual([
      "dev-stera",
      "dev-starpay",
      GENERIC_SECTION_KEY,
    ]);
    // credit + paypay overlap → assigned to Stera (first).
    expect(sections[0].tenderKeys).toEqual([
      "credit",
      "rakuten_pay",
      "paypay",
      "id",
      "quicpay",
    ]);
    expect(sections[1].tenderKeys).toEqual(["wechat_pay", "alipay"]);
    // 銀行Pay is in no device's accepts → generic bucket.
    expect(sections[2].tenderKeys).toEqual(["ginko_pay"]);
    expect(sections[2].deviceName).toBeNull();
  });

  it("everything covered by devices → no generic section at all", () => {
    const covered = TENDERS.filter((t) => t.tender_key !== "ginko_pay");
    const sections = buildTerminalSections([STERA, STARPAY], covered, VISIBLE);
    expect(sections.map((s) => s.key)).toEqual(["dev-stera", "dev-starpay"]);
  });

  it("cash never enters a section; tenders of non-visible categories are excluded", () => {
    const sections = buildTerminalSections([], TENDERS, ["card", "qr"]);
    const keys = sections.flatMap((s) => s.tenderKeys);
    expect(keys).not.toContain("cash");
    expect(keys).not.toContain("id");
    expect(keys).not.toContain("quicpay");
  });

  it("category expected is attributed to the FIRST section holding rows of the category", () => {
    const sections = buildTerminalSections([STERA, STARPAY], TENDERS, VISIBLE);
    // card + qr + emoney all first appear on Stera; StarPay's qr rows do NOT
    // re-own qr (backend only rolls expected per category — see doc).
    expect(sections[0].ownedCategoryKeys).toEqual(["card", "qr", "emoney"]);
    expect(sections[1].ownedCategoryKeys).toEqual([]);
  });

  it("a terminal accepting nothing from the effective list produces no section", () => {
    const foreign: PaymentTerminal = {
      id: "dev-x",
      name: "X",
      accepts: ["momo", "zalopay"],
    };
    const sections = buildTerminalSections([foreign], TENDERS, VISIBLE);
    expect(sections).toHaveLength(1);
    expect(sections[0].key).toBe(GENERIC_SECTION_KEY);
  });

  it("no non-cash tenders → no sections", () => {
    expect(buildTerminalSections([STERA], [tt("cash", "cash", true)], [])).toEqual(
      [],
    );
  });
});

describe("buildTenderBrandGroups", () => {
  const CATEGORIES = [cat("cash", "現金"), cat("card", "クレジット"), cat("qr", "QR決済"), cat("emoney", "電子マネー")];

  it("no terminal data → every effective non-cash tender, grouped by category", () => {
    const groups = buildTenderBrandGroups(TENDERS, CATEGORIES, []);
    expect(groups.map((g) => g.categoryKey)).toEqual(["card", "qr", "emoney"]);
    expect(groups[1].tenders.map((t) => t.tender_key)).toEqual([
      "rakuten_pay",
      "paypay",
      "ginko_pay",
      "wechat_pay",
      "alipay",
    ]);
    expect(groups[0].categoryName).toBe("クレジット");
  });

  it("terminal accepts restrict the list to the union of every device's accepts", () => {
    const groups = buildTenderBrandGroups(TENDERS, CATEGORIES, [STERA, STARPAY]);
    const keys = groups.flatMap((g) => g.tenders.map((t) => t.tender_key));
    expect(keys.sort()).toEqual(
      ["alipay", "credit", "id", "paypay", "quicpay", "rakuten_pay", "wechat_pay"].sort(),
    );
  });

  it("cash is never offered as a brand", () => {
    const groups = buildTenderBrandGroups(TENDERS, CATEGORIES, []);
    expect(groups.flatMap((g) => g.tenders.map((t) => t.tender_key))).not.toContain(
      "cash",
    );
  });

  it("empty categories are dropped", () => {
    const groups = buildTenderBrandGroups(
      TENDERS.filter((t) => t.category !== "emoney"),
      CATEGORIES,
      [],
    );
    expect(groups.map((g) => g.categoryKey)).toEqual(["card", "qr"]);
  });
});

describe("tenderDisplayName", () => {
  it("resolves the active locale, falling back ja → en → vi → first → key", () => {
    const row = tt("paypay", "qr", false, { ja: "PayPay", vi: "PayPay VN" });
    expect(tenderDisplayName(row, "vi")).toBe("PayPay VN");
    expect(tenderDisplayName(row, "en")).toBe("PayPay"); // ja fallback
    expect(tenderDisplayName(tt("x", "qr", false, "楽天ペイ"), "ja")).toBe(
      "楽天ペイ",
    );
    expect(
      tenderDisplayName(tt("y", "qr", false, {} as Record<string, string>), "ja"),
    ).toBe("y");
  });
});

describe("tenderCategoryLabel", () => {
  // A tiny stand-in for pos-web's t(): returns the catalogue string, or the
  // key itself when there is none — the real behaviour the helper guards on.
  const dict: Record<string, string> = {
    "shift.close.reconcile.category.cash": "現金",
    "shift.close.reconcile.category.card": "クレジット",
    "shift.close.reconcile.category.qr": "QR決済",
    "shift.close.reconcile.category.emoney": "電子マネー",
  };
  const t = (key: string) => dict[key] ?? key;

  it("translates the four seeded system headings", () => {
    // The stored name is the seeder's Vietnamese, identical for every
    // organization on the platform — which is why a Japanese shop could never
    // read these in Japanese before, whatever the cashier picked.
    expect(tenderCategoryLabel(cat("card", "Thẻ"), t)).toBe("クレジット");
    expect(tenderCategoryLabel(cat("qr", "QR"), t)).toBe("QR決済");
    expect(tenderCategoryLabel(cat("emoney", "Tiền điện tử"), t)).toBe(
      "電子マネー",
    );
  });

  it("leaves a shop-authored category alone", () => {
    // Not system → user data → exactly one name, in the language it was typed.
    const custom: TillTenderCategoryRow = {
      id: "c1",
      key: "voucher",
      name: "Phiếu quà tặng",
      sort_order: 40,
      is_system: false,
    };
    expect(tenderCategoryLabel(custom, t)).toBe("Phiếu quà tặng");
  });

  it("falls back to the stored name when the catalogue has no entry", () => {
    // An is_system key the client build has never heard of must not render as
    // a raw i18n key on a cashier's screen.
    expect(tenderCategoryLabel(cat("crypto", "Tiền mã hoá"), t)).toBe(
      "Tiền mã hoá",
    );
  });

  it("heads the payment dialog's brand groups in the operator's language", () => {
    const groups = buildTenderBrandGroups(
      TENDERS,
      [cat("card", "Thẻ"), cat("qr", "QR"), cat("emoney", "Tiền điện tử")],
      [],
      (c) => tenderCategoryLabel(c, t),
    );
    expect(groups.map((g) => g.categoryName)).toEqual([
      "クレジット",
      "QR決済",
      "電子マネー",
    ]);
  });

  it("keeps the stored name when no labeller is passed", () => {
    const groups = buildTenderBrandGroups(TENDERS, [cat("qr", "QR")], []);
    expect(groups[0]?.categoryName).toBe("QR");
  });
});
