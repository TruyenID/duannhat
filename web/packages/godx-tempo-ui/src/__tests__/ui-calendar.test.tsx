import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Calendar } from "../calendar";

describe("Calendar", () => {
  it("renders without crashing", () => {
    const { container } = render(<Calendar />);
    expect(container.firstChild).toBeInTheDocument();
  });
});
