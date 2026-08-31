import React from "react";
import { describe, it, expect } from "vitest";

describe("DatePicker", () => {
  it("module exists", async () => {
    const mod = await import("../date-picker");
    expect(mod.DatePicker).toBeDefined();
  });
});
