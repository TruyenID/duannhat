import { describe, expect, it } from "vitest";

import { collapseMirroredToppingName } from "./topping-name";

describe("collapseMirroredToppingName", () => {
  it("collapses an exact doubled name to a single label", () => {
    expect(collapseMirroredToppingName("Fish sauce · Fish sauce")).toBe(
      "Fish sauce",
    );
    expect(collapseMirroredToppingName("100% sugar · 100% sugar")).toBe(
      "100% sugar",
    );
    expect(collapseMirroredToppingName("Iced · Iced")).toBe("Iced");
  });

  it("leaves a genuine Product · Variant name untouched", () => {
    expect(collapseMirroredToppingName("Phô mai · Large")).toBe(
      "Phô mai · Large",
    );
  });

  it("leaves a plain single-label name untouched", () => {
    expect(collapseMirroredToppingName("Veggies")).toBe("Veggies");
  });

  it("collapses regardless of case / surrounding whitespace", () => {
    expect(collapseMirroredToppingName("Iced ·  iced")).toBe("Iced");
  });

  it("collapses when the same label repeats 3+ times", () => {
    expect(collapseMirroredToppingName("Iced · Iced · Iced")).toBe("Iced");
  });

  it("returns an empty string for null/undefined/empty input", () => {
    expect(collapseMirroredToppingName(null)).toBe("");
    expect(collapseMirroredToppingName(undefined)).toBe("");
    expect(collapseMirroredToppingName("")).toBe("");
  });
});
