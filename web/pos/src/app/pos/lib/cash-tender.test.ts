/**
 * Cash tendered / change for one split-bill row.
 *
 * This is the number a customer counts in their hand, so every case is
 * asserted EXACTLY — no "greater than zero" assertions. The invalid cases
 * matter as much as the valid ones: both servers refuse `tendered < amount`,
 * so anything this calls valid must be postable and anything it calls invalid
 * must be blocked before it becomes a 422 mid-checkout.
 */

import { afterEach, describe, expect, it } from "vitest";
import {
  MAX_TENDERED_AMOUNT,
  addQuickTender,
  cashQuickTenders,
  computeCashTender,
  formatQuickTenderLabel,
} from "./cash-tender";
import { setActiveCurrency } from "./totals";

afterEach(() => {
  setActiveCurrency("VND");
});

describe("computeCashTender — the exact-share default", () => {
  it("treats an untouched field as tendering the exact share", () => {
    // This is what keeps the pre-existing one-tap flow (pick method → Thu)
    // working: no typing required, and the field visibly shows the share.
    const r = computeCashTender(null, 115_000, "VND");
    expect(r).toEqual({
      tendered: 115_000,
      change: 0,
      shortfall: 0,
      valid: true,
      exact: true,
      problem: "none",
    });
  });

  it("treats a blank / whitespace field the same as untouched", () => {
    expect(computeCashTender("", 50_000, "VND").tendered).toBe(50_000);
    expect(computeCashTender("   ", 50_000, "VND").tendered).toBe(50_000);
    expect(computeCashTender("   ", 50_000, "VND").valid).toBe(true);
  });

  it("reports an exact typed tender as exact, with zero change", () => {
    const r = computeCashTender("50000", 50_000, "VND");
    expect(r.change).toBe(0);
    expect(r.valid).toBe(true);
    expect(r.exact).toBe(true);
  });
});

describe("computeCashTender — change", () => {
  it("returns the surplus when the guest overpays", () => {
    const r = computeCashTender("200000", 115_000, "VND");
    expect(r.tendered).toBe(200_000);
    expect(r.change).toBe(85_000);
    expect(r.shortfall).toBe(0);
    expect(r.valid).toBe(true);
    expect(r.exact).toBe(false);
  });

  it("handles the ¥2,000-note-on-a-¥1,793-bill case exactly", () => {
    // The shape of the original report: the screen was right, the slip said
    // "tendered = bill, change = 0". Here the numbers must survive intact.
    const r = computeCashTender("2000", 1_793, "JPY");
    expect(r.tendered).toBe(2_000);
    expect(r.change).toBe(207);
  });

  it("does not produce floating-point dust on a 2-decimal currency", () => {
    const r = computeCashTender("20", 13.37, "USD");
    expect(r.change).toBe(6.63);
  });
});

describe("computeCashTender — refusals", () => {
  it("is invalid when the tender is below the share, and says how short", () => {
    const r = computeCashTender("100000", 115_000, "VND");
    expect(r.valid).toBe(false);
    expect(r.problem).toBe("short");
    expect(r.shortfall).toBe(15_000);
    expect(r.change).toBe(0);
    // The typed figure is still reported so the UI can echo it back.
    expect(r.tendered).toBe(100_000);
  });

  it("is invalid — never silently 'exact' — for unparseable text", () => {
    // Falling back to the share here would post a number the cashier never
    // agreed to, on a row they were still typing into.
    for (const junk of ["abc", "1,000", "--5", "1e"]) {
      const r = computeCashTender(junk, 50_000, "VND");
      expect(r.valid, junk).toBe(false);
      expect(r.tendered, junk).toBeNull();
      // "unparseable", never "short": the row must not claim the guest is
      // short by the whole bill when the box simply holds letters.
      expect(r.problem, junk).toBe("unparseable");
    }
  });

  it("is invalid for a negative tender", () => {
    const r = computeCashTender("-1000", 50_000, "VND");
    expect(r.valid).toBe(false);
    expect(r.tendered).toBeNull();
  });

  it("refuses a tender above what Cloud can store", () => {
    // Cloud validates `tendered_amount` as max:99999999. A bigger figure is
    // accepted by the workstation and then dead-letters the sync UP forever,
    // so an extra digit here would quietly detach a real payment from Cloud.
    expect(computeCashTender("99999999", 1_000, "VND").valid).toBe(true);
    const over = computeCashTender("100000000", 1_000, "VND");
    expect(over.valid).toBe(false);
    expect(over.change).toBe(0);
    // "too_large", never "short" — shortfall is 0 here and a "còn thiếu 0 ₫"
    // line is nonsense the cashier cannot act on.
    expect(over.problem).toBe("too_large");
    expect(over.shortfall).toBe(0);
  });

  it("treats a zero share as owing nothing", () => {
    const r = computeCashTender(null, 0, "VND");
    expect(r.valid).toBe(true);
    expect(r.tendered).toBe(0);
    expect(r.change).toBe(0);
  });
});

describe("computeCashTender — currency resolution", () => {
  it("falls back to the active session currency when none is passed", () => {
    setActiveCurrency("USD");
    expect(computeCashTender("20", 13.37).change).toBe(6.63);
  });
});

describe("the Cloud storage ceiling", () => {
  it("is the value Cloud's workstation payment route validates against", () => {
    expect(MAX_TENDERED_AMOUNT).toBe(99_999_999);
  });
});

describe("quick-tender chips", () => {
  it("offers denominations of the shop's own currency (#555 L1)", () => {
    expect(cashQuickTenders("JPY")).toEqual([1_000, 2_000, 5_000, 10_000]);
    expect(cashQuickTenders("VND")).toEqual([50_000, 100_000, 200_000, 500_000]);
    expect(cashQuickTenders("USD")).toEqual([5, 10, 20, 50]);
  });

  it("falls back to VND for a currency with no table", () => {
    expect(cashQuickTenders("XXX")).toEqual([50_000, 100_000, 200_000, 500_000]);
  });

  it("labels chips compactly", () => {
    expect(formatQuickTenderLabel(500_000)).toBe("500k");
    expect(formatQuickTenderLabel(1_000_000)).toBe("1M");
    expect(formatQuickTenderLabel(20)).toBe("20");
  });

  it("adds to the running tender — cash arrives as a stack of notes", () => {
    expect(addQuickTender("200000", 200_000)).toBe("400000");
    expect(addQuickTender("400000", 50_000)).toBe("450000");
  });

  it("starts an untouched field from the chip, NOT from the share", () => {
    // Starting from the share would silently record the bill PLUS the note.
    expect(addQuickTender(null, 100_000)).toBe("100000");
    expect(addQuickTender("", 100_000)).toBe("100000");
    expect(addQuickTender("abc", 100_000)).toBe("100000");
  });
});
