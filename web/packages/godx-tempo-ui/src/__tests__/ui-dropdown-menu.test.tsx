import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect } from "vitest";
import { DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuItem } from "../dropdown-menu";

describe("DropdownMenu", () => {
  it("opens on trigger click", async () => {
    render(<DropdownMenu><DropdownMenuTrigger>Menu</DropdownMenuTrigger><DropdownMenuContent><DropdownMenuItem>Item 1</DropdownMenuItem><DropdownMenuItem>Item 2</DropdownMenuItem></DropdownMenuContent></DropdownMenu>);
    await userEvent.click(screen.getByText("Menu"));
    expect(screen.getByText("Item 1")).toBeInTheDocument();
    expect(screen.getByText("Item 2")).toBeInTheDocument();
  });
});
