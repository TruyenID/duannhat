import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect } from "vitest";
import { Collapsible, CollapsibleTrigger, CollapsibleContent } from "../collapsible";

describe("Collapsible", () => {
  it("expands on trigger click", async () => {
    render(<Collapsible><CollapsibleTrigger>Toggle</CollapsibleTrigger><CollapsibleContent>Hidden content</CollapsibleContent></Collapsible>);
    await userEvent.click(screen.getByText("Toggle"));
    expect(screen.getByText("Hidden content")).toBeInTheDocument();
  });
});
