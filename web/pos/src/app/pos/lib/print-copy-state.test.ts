import { describe, expect, it } from "vitest";

import { printCopyState } from "./print-copy-state";
import type { PrintKindCounts } from "@/services/workstation-print-service";

const counts: PrintKindCounts = {
  printed: true,
  order_scope: { count: 1, last_status: "printed" },
  by_payment: [
    { payment_id: "pay-a", count: 2, last_status: "printed" },
    { payment_id: "pay-b", count: 0 },
  ],
};

describe("printCopyState", () => {
  it("reads the named payer, not the order", () => {
    const state = printCopyState(counts, "pay-a");
    expect(state).toMatchObject({
      alreadyPrinted: true,
      printedCount: 2,
      nextCopyNo: 3,
      unknown: false,
    });
  });

  // The rule the whole issue is about: on a split bill, a guest who has no
  // paper yet gets a CLEAN first sheet even though another guest has two.
  it("keeps a payer with no paper at a clean first copy", () => {
    expect(printCopyState(counts, "pay-b")).toMatchObject({
      alreadyPrinted: false,
      nextCopyNo: 1,
      unknown: false,
    });
  });

  it("treats a payer absent from the tally as a known zero", () => {
    expect(printCopyState(counts, "pay-never-seen")).toMatchObject({
      alreadyPrinted: false,
      nextCopyNo: 1,
      unknown: false,
    });
  });

  // The whole-order slip is its own document. Summing the payers here would
  // warn about copies of a sheet that was never printed.
  it("uses order_scope when no payer is named", () => {
    expect(printCopyState(counts, undefined)).toMatchObject({
      printedCount: 1,
      nextCopyNo: 2,
    });
  });

  // A workstation older than #1875 sends no counts. Claiming "chưa in" from a
  // missing field is the failure mode that puts a second original in a
  // customer's hand, so this must report unknown and the UI must stay silent.
  it("reports unknown — never a confident zero — when counts are absent", () => {
    expect(printCopyState(undefined, "pay-a")).toMatchObject({
      alreadyPrinted: false,
      unknown: true,
    });
  });
});
