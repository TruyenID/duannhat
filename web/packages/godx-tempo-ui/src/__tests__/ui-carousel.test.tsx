import React from "react";
import { describe, it, expect } from "vitest";

describe("Carousel", () => {
  it("module exists", async () => {
    const mod = await import("../carousel");
    expect(mod.Carousel).toBeDefined();
    expect(mod.CarouselContent).toBeDefined();
    expect(mod.CarouselItem).toBeDefined();
  });
});
