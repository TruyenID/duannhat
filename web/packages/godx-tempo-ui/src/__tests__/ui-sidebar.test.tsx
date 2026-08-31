import React from "react";
import { describe, it, expect } from "vitest";

describe("Sidebar", () => {
  it("module exists", async () => {
    const mod = await import("../sidebar");
    expect(mod).toBeDefined();
  });
});
