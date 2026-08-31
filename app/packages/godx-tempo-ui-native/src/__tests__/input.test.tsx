import React from "react";
import { render, screen, fireEvent } from "@testing-library/react";
import { Input } from "../components/input";

describe("Input", () => {
  it("renders with placeholder", () => {
    render(<Input placeholder="Enter text" />);
    expect(screen.getByPlaceholderText("Enter text")).toBeTruthy();
  });

  it("renders with value", () => {
    render(<Input value="hello" onChangeText={jest.fn()} />);
    expect(screen.getByDisplayValue("hello")).toBeTruthy();
  });

  it("calls onChangeText when typing", () => {
    const onChange = jest.fn();
    render(<Input placeholder="Type" onChangeText={onChange} />);
    fireEvent.change(screen.getByPlaceholderText("Type"), { target: { value: "world" } });
    expect(onChange).toHaveBeenCalledWith("world");
  });

  it("renders as non-editable", () => {
    render(<Input placeholder="Disabled" editable={false} />);
    const input = screen.getByPlaceholderText("Disabled");
    // Mock maps editable=false → disabled attribute
    expect(input.hasAttribute("disabled") || input.getAttribute("aria-disabled") === "true").toBe(true);
  });
});
