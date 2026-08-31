import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect } from "vitest";
import { Popover, PopoverTrigger, PopoverContent } from "../popover";

describe("Popover", () => {
  it("opens when trigger is clicked", async () => {
    render(<Popover><PopoverTrigger>Info</PopoverTrigger><PopoverContent>Details here</PopoverContent></Popover>);
    await userEvent.click(screen.getByText("Info"));
    expect(screen.getByText("Details here")).toBeInTheDocument();
  });
});
