import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect } from "vitest";
import { Sheet, SheetTrigger, SheetContent, SheetHeader, SheetTitle } from "../sheet";

describe("Sheet", () => {
  it("opens when trigger is clicked", async () => {
    render(<Sheet><SheetTrigger>Open</SheetTrigger><SheetContent><SheetHeader><SheetTitle>Sheet Title</SheetTitle></SheetHeader></SheetContent></Sheet>);
    await userEvent.click(screen.getByText("Open"));
    expect(screen.getByText("Sheet Title")).toBeInTheDocument();
  });
});
