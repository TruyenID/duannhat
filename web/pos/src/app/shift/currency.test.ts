import { describe, expect, it } from "vitest";
import { sumCountedCash } from "./currency";

describe("sumCountedCash — odd change / adjustment (issue #542)", () => {
  it("adds the denomination total and the odd-change adjustment", () => {
    expect(sumCountedCash(123000, 123)).toBe(123123);
  });

  it("returns the denomination total unchanged when adjustment is 0", () => {
    expect(sumCountedCash(50000, 0)).toBe(50000);
  });

  it("keeps sub-unit change exact to the cent (no binary-float drift)", () => {
    // 0.1 + 0.2 is the canonical IEEE-754 drift case → 0.30000000000000004.
    expect(sumCountedCash(0.1, 0.2)).toBe(0.3);
  });

  it("rounds a fractional total to 2 decimals like the backend", () => {
    expect(sumCountedCash(10.005, 0)).toBe(10.01);
    expect(sumCountedCash(123.123, 0.456)).toBe(123.58);
  });

  it("supports a drawer holding only odd change (empty denomination table)", () => {
    expect(sumCountedCash(0, 0.75)).toBe(0.75);
  });
});
