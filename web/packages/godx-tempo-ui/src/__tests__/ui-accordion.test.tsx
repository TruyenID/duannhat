import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect } from "vitest";
import { Accordion, AccordionItem, AccordionTrigger, AccordionContent } from "../accordion";

describe("Accordion", () => {
  it("renders items", () => {
    render(<Accordion type="single" collapsible><AccordionItem value="a"><AccordionTrigger>Item A</AccordionTrigger><AccordionContent>Content A</AccordionContent></AccordionItem></Accordion>);
    expect(screen.getByText("Item A")).toBeInTheDocument();
  });
  it("expands on click", async () => {
    render(<Accordion type="single" collapsible><AccordionItem value="a"><AccordionTrigger>Click</AccordionTrigger><AccordionContent>Expanded</AccordionContent></AccordionItem></Accordion>);
    await userEvent.click(screen.getByText("Click"));
    expect(screen.getByText("Expanded")).toBeInTheDocument();
  });
});
