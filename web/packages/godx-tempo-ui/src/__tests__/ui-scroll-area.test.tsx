import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { ScrollArea } from "../scroll-area";

describe("ScrollArea", () => {
  it("renders children", () => {
    render(<ScrollArea className="h-40"><p>Scrollable content</p></ScrollArea>);
    expect(screen.getByText("Scrollable content")).toBeInTheDocument();
  });
});
