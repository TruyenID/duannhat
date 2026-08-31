import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect } from "vitest";
import { Drawer, DrawerTrigger, DrawerContent, DrawerHeader, DrawerTitle } from "../drawer";

describe("Drawer", () => {
  it("opens when trigger is clicked", async () => {
    render(<Drawer><DrawerTrigger>Open Drawer</DrawerTrigger><DrawerContent><DrawerHeader><DrawerTitle>Drawer Title</DrawerTitle></DrawerHeader></DrawerContent></Drawer>);
    await userEvent.click(screen.getByText("Open Drawer"));
    expect(screen.getByText("Drawer Title")).toBeInTheDocument();
  });
});
