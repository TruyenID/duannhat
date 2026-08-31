import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Toaster } from "../sonner";

describe("Sonner/Toaster", () => {
  it("renders without crashing", () => {
    const { container } = render(<Toaster />);
    expect(container).toBeInTheDocument();
  });
});
