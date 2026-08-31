import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { ContextMenu, ContextMenuTrigger, ContextMenuContent, ContextMenuItem } from "../context-menu";

describe("ContextMenu", () => {
  it("renders trigger", () => {
    render(<ContextMenu><ContextMenuTrigger>Right click</ContextMenuTrigger><ContextMenuContent><ContextMenuItem>Copy</ContextMenuItem></ContextMenuContent></ContextMenu>);
    expect(screen.getByText("Right click")).toBeInTheDocument();
  });
});
