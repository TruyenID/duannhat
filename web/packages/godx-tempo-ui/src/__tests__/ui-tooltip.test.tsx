import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Tooltip, TooltipTrigger, TooltipContent, TooltipProvider } from "../tooltip";

describe("Tooltip", () => {
  it("renders trigger", () => {
    render(<TooltipProvider><Tooltip><TooltipTrigger>Hover me</TooltipTrigger><TooltipContent>Tip</TooltipContent></Tooltip></TooltipProvider>);
    expect(screen.getByText("Hover me")).toBeInTheDocument();
  });
});
