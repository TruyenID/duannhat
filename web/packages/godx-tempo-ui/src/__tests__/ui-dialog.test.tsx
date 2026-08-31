import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect } from "vitest";
import { Dialog, DialogTrigger, DialogContent, DialogTitle, DialogDescription } from "../dialog";

describe("Dialog", () => {
  it("opens when trigger is clicked", async () => {
    render(<Dialog><DialogTrigger>Open</DialogTrigger><DialogContent><DialogTitle>Title</DialogTitle><DialogDescription>Desc</DialogDescription></DialogContent></Dialog>);
    await userEvent.click(screen.getByText("Open"));
    expect(screen.getByText("Title")).toBeInTheDocument();
  });
});
