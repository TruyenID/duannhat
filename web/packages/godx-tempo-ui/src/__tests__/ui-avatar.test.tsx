import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Avatar, AvatarFallback } from "../avatar";

describe("Avatar", () => {
  it("renders fallback text", () => { const { getByText } = render(<Avatar><AvatarFallback>JD</AvatarFallback></Avatar>); expect(getByText("JD")).toBeInTheDocument(); });
  it("applies className", () => { const { container } = render(<Avatar className="h-12 w-12"><AvatarFallback>A</AvatarFallback></Avatar>); expect(container.firstChild).toHaveClass("h-12"); });
});
