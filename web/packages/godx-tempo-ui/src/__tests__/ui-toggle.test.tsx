import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect, vi } from "vitest";
import { Toggle } from "../toggle";

describe("Toggle", () => {
  it("renders", () => { render(<Toggle>Bold</Toggle>); expect(screen.getByText("Bold")).toBeInTheDocument(); });
  it("toggles pressed state", async () => {
    const onChange = vi.fn();
    render(<Toggle onPressedChange={onChange}>B</Toggle>);
    await userEvent.click(screen.getByText("B"));
    expect(onChange).toHaveBeenCalledWith(true);
  });
});
