import { describe, expect, it } from "vitest";
import { isProvisionalCode } from "./order-code";

describe("isProvisionalCode", () => {
  it("treats a finalized ORD code as not provisional", () => {
    expect(isProvisionalCode("ORD-2026-0004")).toBe(false);
  });

  it("treats the workstation WS- code as provisional", () => {
    expect(isProvisionalCode("WS-A1B2-20260625-014")).toBe(true);
  });

  it("treats empty / null / undefined as provisional", () => {
    expect(isProvisionalCode("")).toBe(true);
    expect(isProvisionalCode(null)).toBe(true);
    expect(isProvisionalCode(undefined)).toBe(true);
  });

  it("does not false-match a substring", () => {
    expect(isProvisionalCode("PENDING-ORD-1")).toBe(true);
  });
});
