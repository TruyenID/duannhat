import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { HoverCard, HoverCardTrigger, HoverCardContent } from "../hover-card";

describe("HoverCard", () => {
  it("renders trigger", () => {
    render(<HoverCard><HoverCardTrigger>Hover</HoverCardTrigger><HoverCardContent>Card content</HoverCardContent></HoverCard>);
    expect(screen.getByText("Hover")).toBeInTheDocument();
  });
});
