import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Menubar, MenubarMenu, MenubarTrigger, MenubarContent, MenubarItem } from "../menubar";

describe("Menubar", () => {
  it("renders menu triggers", () => {
    render(<Menubar><MenubarMenu><MenubarTrigger>File</MenubarTrigger><MenubarContent><MenubarItem>New</MenubarItem></MenubarContent></MenubarMenu></Menubar>);
    expect(screen.getByText("File")).toBeInTheDocument();
  });
});
