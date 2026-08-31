import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { TimePicker } from "../time-picker";

describe("TimePicker", () => {
  it("renders without crashing", () => {
    const { container } = render(<TimePicker />);
    expect(container).toBeInTheDocument();
  });
});
