import { describe, expect, it } from "vitest";
import type { TillTenderType } from "@/services/till-service";
import {
  computeSectionReconciles,
  computeTerminalReconcile,
  type TerminalReconcileInput,
} from "./terminal-reconcile";
import {
  buildTerminalSections,
  type PaymentTerminal,
} from "./tender-terminals";

// Minimal tender-type factory — only the fields the reconciler reads.
function tt(
  tender_key: string,
  category: string,
  is_expected_anchor = false,
): TillTenderType {
  return {
    tender_key,
    category,
    is_expected_anchor,
  } as unknown as TillTenderType;
}

// A shop where ALL non-cash rides the card terminal (card_terminal merged into
// card → category_expected.card carries it), plus QR/e-money buckets at 0.
const TENDER_TYPES: TillTenderType[] = [
  tt("cash", "cash", true),
  tt("credit", "card", true),
  tt("rakuten_pay", "qr"),
  tt("paypay", "qr"),
  tt("id", "emoney"),
];

const VISIBLE = ["card", "qr", "emoney"];

function build(
  over: Partial<TerminalReconcileInput> = {},
): TerminalReconcileInput {
  return {
    tenderTypes: TENDER_TYPES,
    tenders: {},
    categoryDeclared: {},
    categoryExpected: { card: 6578, qr: 0, emoney: 0 },
    expectedByTender: { cash: null, credit: 6578 },
    visibleCategoryKeys: VISIBLE,
    tolerance: 0,
    ...over,
  };
}

describe("computeTerminalReconcile", () => {
  it("systemTotal = Σ non-cash expected; declared 0 before entry", () => {
    const r = computeTerminalReconcile(build());
    expect(r.systemTotal).toBe(6578);
    expect(r.declaredTotal).toBe(0);
    expect(r.variance).toBe(-6578);
  });

  it("nothing entered → the card anchor is a reason carrier (whole terminal unreconciled)", () => {
    const r = computeTerminalReconcile(build());
    expect(r.reasonCarrierKeys.has("credit")).toBe(true);
    expect(r.reasonCarrierKeys.size).toBe(1);
  });

  it("card entered to match → variance 0, no reason needed", () => {
    const r = computeTerminalReconcile(
      build({
        tenders: { credit: { gross: "6578", cancel: "" } },
        categoryDeclared: { card: 6578 },
      }),
    );
    expect(r.declaredTotal).toBe(6578);
    expect(r.variance).toBe(0);
    expect(r.reasonCarrierKeys.size).toBe(0);
  });

  it("gross − cancel is the net that counts", () => {
    const r = computeTerminalReconcile(
      build({
        tenders: { credit: { gross: "7000", cancel: "422" } },
        categoryDeclared: { card: 6578 },
      }),
    );
    expect(r.declaredTotal).toBe(6578);
    expect(r.variance).toBe(0);
    expect(r.reasonCarrierKeys.size).toBe(0);
  });

  it("card mis-entered beyond tolerance → the credit anchor carries the reason", () => {
    const r = computeTerminalReconcile(
      build({
        tenders: { credit: { gross: "6000", cancel: "" } },
        categoryDeclared: { card: 6000 },
      }),
    );
    expect(r.variance).toBe(-578);
    expect(r.reasonCarrierKeys.has("credit")).toBe(true);
  });

  it("within tolerance → no carrier even if not exact", () => {
    const r = computeTerminalReconcile(
      build({
        tolerance: 10,
        tenders: { credit: { gross: "6570", cancel: "" } },
        categoryDeclared: { card: 6570 },
      }),
    );
    expect(r.variance).toBe(-8);
    expect(r.reasonCarrierKeys.size).toBe(0);
  });

  it("split card+QR entered correctly → balanced, no carriers", () => {
    const r = computeTerminalReconcile(
      build({
        categoryExpected: { card: 5000, qr: 1578, emoney: 0 },
        expectedByTender: { cash: null, credit: 5000 },
        tenders: {
          credit: { gross: "5000", cancel: "" },
          paypay: { gross: "1578", cancel: "" },
        },
        categoryDeclared: { card: 5000, qr: 1578 },
      }),
    );
    expect(r.variance).toBe(0);
    expect(r.reasonCarrierKeys.size).toBe(0);
  });

  it("QR expected but untouched → first QR row becomes the carrier (untouched-but-diverging)", () => {
    const r = computeTerminalReconcile(
      build({
        categoryExpected: { card: 5000, qr: 1578, emoney: 0 },
        expectedByTender: { cash: null, credit: 5000 },
        tenders: { credit: { gross: "5000", cancel: "" } },
        categoryDeclared: { card: 5000 },
      }),
    );
    // card reconciles (5000=5000); qr diverges (0 vs 1578) → carrier = rakuten_pay (first QR row).
    expect(r.variance).toBe(-1578);
    expect(r.reasonCarrierKeys.has("rakuten_pay")).toBe(true);
    expect(r.reasonCarrierKeys.has("credit")).toBe(false);
  });

  it("QR diverging but one row touched → the touched row carries the reason (not the first)", () => {
    const r = computeTerminalReconcile(
      build({
        categoryExpected: { card: 5000, qr: 1578, emoney: 0 },
        expectedByTender: { cash: null, credit: 5000 },
        tenders: {
          credit: { gross: "5000", cancel: "" },
          paypay: { gross: "1000", cancel: "" }, // touched, but short
        },
        categoryDeclared: { card: 5000, qr: 1000 },
      }),
    );
    expect(r.variance).toBe(-578);
    expect(r.reasonCarrierKeys.has("paypay")).toBe(true);
    expect(r.reasonCarrierKeys.has("rakuten_pay")).toBe(false);
  });

  it("cash-only shop (no non-cash tenders) → empty result", () => {
    const r = computeTerminalReconcile(
      build({
        tenderTypes: [tt("cash", "cash", true)],
        visibleCategoryKeys: [],
        categoryExpected: {},
        expectedByTender: { cash: null },
      }),
    );
    expect(r.systemTotal).toBe(0);
    expect(r.declaredTotal).toBe(0);
    expect(r.reasonCarrierKeys.size).toBe(0);
  });
});

// ===========================================================================
//  #1156 — per-terminal sections
// ===========================================================================

// Stera takes credit + iD; a second StarPay unit takes the QR wallets.
const STERA: PaymentTerminal = {
  id: "dev-stera",
  name: "Stera terminal 01",
  accepts: ["credit", "id"],
};
const STARPAY: PaymentTerminal = {
  id: "dev-starpay",
  name: "StarPay 01",
  accepts: ["paypay", "rakuten_pay"],
};

function sectionsFor(terminals: PaymentTerminal[]) {
  return buildTerminalSections(terminals, TENDER_TYPES, VISIBLE);
}

describe("computeSectionReconciles", () => {
  it("no sections → empty result", () => {
    expect(computeSectionReconciles(build(), [])).toEqual([]);
  });

  it("single generic section (no devices) reproduces the single-terminal math exactly", () => {
    const input = build({
      tenders: { credit: { gross: "6000", cancel: "" } },
      categoryDeclared: { card: 6000 },
    });
    const global = computeTerminalReconcile(input);
    const [sr] = computeSectionReconciles(input, sectionsFor([]));
    expect(sr.declaredTotal).toBe(global.declaredTotal);
    expect(sr.systemTotal).toBe(global.systemTotal);
    expect(sr.variance).toBe(global.variance);
    expect([...sr.reasonCarrierKeys].sort()).toEqual(
      [...global.reasonCarrierKeys].sort(),
    );
  });

  it("two terminals split declared per section; carriers partition to their own section", () => {
    const input = build({
      categoryExpected: { card: 5000, qr: 1578, emoney: 0 },
      expectedByTender: { cash: null, credit: 5000 },
      tenders: {
        credit: { gross: "5000", cancel: "" },
        paypay: { gross: "1000", cancel: "" }, // short by 578 → qr carrier
      },
      categoryDeclared: { card: 5000, qr: 1000 },
    });
    const [stera, starpay] = computeSectionReconciles(
      input,
      sectionsFor([STERA, STARPAY]),
    );

    // Stera: credit (5000) + id (0) — card + emoney categories attributed here.
    expect(stera.section.key).toBe("dev-stera");
    expect(stera.declaredTotal).toBe(5000);
    expect(stera.systemTotal).toBe(5000);
    expect(stera.variance).toBe(0);
    expect(stera.reasonCarrierKeys.size).toBe(0);

    // StarPay: paypay + rakuten_pay — qr expected attributed here.
    expect(starpay.section.key).toBe("dev-starpay");
    expect(starpay.declaredTotal).toBe(1000);
    expect(starpay.systemTotal).toBe(1578);
    expect(starpay.variance).toBe(-578);
    expect(starpay.reasonCarrierKeys.has("paypay")).toBe(true);
  });

  it("union of per-section carriers always equals the global carrier set (backend gate parity)", () => {
    const input = build({
      categoryExpected: { card: 5000, qr: 1578, emoney: 300 },
      expectedByTender: { cash: null, credit: 5000 },
      tenders: {},
      categoryDeclared: {},
    });
    const global = computeTerminalReconcile(input);
    const perSection = computeSectionReconciles(
      input,
      sectionsFor([STERA, STARPAY]),
    );
    const union = new Set(
      perSection.flatMap((sr) => [...sr.reasonCarrierKeys]),
    );
    expect([...union].sort()).toEqual([...global.reasonCarrierKeys].sort());
  });

  it("Σ section declared/system equals the global totals", () => {
    const input = build({
      categoryExpected: { card: 5000, qr: 1578, emoney: 300 },
      expectedByTender: { cash: null, credit: 5000 },
      tenders: {
        credit: { gross: "5000", cancel: "" },
        paypay: { gross: "1578", cancel: "" },
        id: { gross: "300", cancel: "" },
      },
      categoryDeclared: { card: 5000, qr: 1578, emoney: 300 },
    });
    const global = computeTerminalReconcile(input);
    const perSection = computeSectionReconciles(
      input,
      sectionsFor([STERA, STARPAY]),
    );
    const declaredSum = perSection.reduce((s, r) => s + r.declaredTotal, 0);
    const systemSum = perSection.reduce((s, r) => s + r.systemTotal, 0);
    expect(declaredSum).toBe(global.declaredTotal);
    expect(systemSum).toBe(global.systemTotal);
    expect(perSection.every((r) => r.variance === 0)).toBe(true);
  });

  it("a carrier belonging to no section is attached to the LAST section (reason still collected)", () => {
    // qr diverges but the section builder only sees the card category →
    // the qr carrier has no home section and lands on the last one.
    const input = build({
      categoryExpected: { card: 5000, qr: 1578 },
      expectedByTender: { cash: null, credit: 5000 },
      tenders: { credit: { gross: "5000", cancel: "" } },
      categoryDeclared: { card: 5000 },
      visibleCategoryKeys: ["card", "qr"],
    });
    const cardOnlySections = buildTerminalSections(
      [STERA],
      TENDER_TYPES,
      ["card"], // qr hidden from the builder
    );
    const result = computeSectionReconciles(input, cardOnlySections);
    const last = result[result.length - 1];
    expect(last.reasonCarrierKeys.has("rakuten_pay")).toBe(true);
  });
});

/**
 * #2616 — ca đã xảy ra trên production (本郷店): hai tổng hiển thị đều `±0` mà
 * nút "Kết ca cuối" vẫn bị khoá, không nói vì sao.
 *
 * Luật khoá chạy ở mức TỪNG DÒNG (anchor) và TỪNG NHÓM (category), còn UI thì
 * hiện TỔNG. Hai dòng lệch ngược dấu triệt tiêu nhau ⇒ tổng `±0` xanh mướt,
 * trong khi mỗi dòng vẫn vượt ngưỡng. Trước bản này con số lệch bị vứt ngay sau
 * phép so sánh nên không đâu nói được dòng nào — thu ngân đứng trước một màn
 * hình toàn số 0 và một lỗi chung chung, giữa lúc kết ca.
 */
describe("#2616 — reasonCarriers mang theo con số, không chỉ key", () => {
  // Thẻ dư +500, QR thiếu −500 ⇒ tổng khai = tổng kỳ vọng.
  const OPPOSITE_SIGNS = build({
    tenders: { credit: { gross: "7078", cancel: "0" } },
    categoryDeclared: { card: 7078, qr: -500, emoney: 0 },
    categoryExpected: { card: 6578, qr: 0, emoney: 0 },
    expectedByTender: { cash: null, credit: 6578 },
  });

  it("tổng section bằng 0 nhưng vẫn có carrier — đúng cái làm nút bị khoá", () => {
    const r = computeTerminalReconcile(OPPOSITE_SIGNS);

    expect(r.variance).toBe(0);
    expect(r.reasonCarriers.length).toBeGreaterThan(0);
  });

  it("mỗi carrier nói RÕ lệch bao nhiêu và theo luật nào", () => {
    const r = computeTerminalReconcile(OPPOSITE_SIGNS);

    const card = r.reasonCarriers.find((c) => c.tenderKey === "credit");
    expect(card).toBeDefined();
    expect(card!.variance).toBe(500);
    expect(card!.rule).toBe("anchor");

    const qr = r.reasonCarriers.find((c) => c.rule === "category");
    expect(qr).toBeDefined();
    expect(qr!.variance).toBe(-500);
  });

  /**
   * `reasonCarrierKeys` phải là ĐÚNG tập key của `reasonCarriers`. Hai nguồn
   * lệch nhau nghĩa là nút khoá vì một dòng mà UI lại chỉ ra dòng khác — tệ hơn
   * không chỉ gì cả.
   */
  it("reasonCarrierKeys và reasonCarriers luôn cùng một tập", () => {
    for (const input of [OPPOSITE_SIGNS, build()]) {
      const r = computeTerminalReconcile(input);
      expect([...r.reasonCarrierKeys].sort()).toEqual(
        r.reasonCarriers.map((c) => c.tenderKey).sort(),
      );
    }
  });

  it("khớp hoàn toàn thì không carrier nào", () => {
    const r = computeTerminalReconcile(
      build({
        tenders: { credit: { gross: "6578", cancel: "0" } },
        categoryDeclared: { card: 6578, qr: 0, emoney: 0 },
      }),
    );

    expect(r.reasonCarriers).toEqual([]);
    expect(r.reasonCarrierKeys.size).toBe(0);
  });
});
