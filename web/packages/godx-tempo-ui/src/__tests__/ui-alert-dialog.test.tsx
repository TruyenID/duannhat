import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect } from "vitest";
import { AlertDialog, AlertDialogTrigger, AlertDialogContent, AlertDialogTitle, AlertDialogDescription, AlertDialogAction, AlertDialogCancel } from "../alert-dialog";

describe("AlertDialog", () => {
  it("opens when trigger is clicked", async () => {
    render(<AlertDialog><AlertDialogTrigger>Delete</AlertDialogTrigger><AlertDialogContent><AlertDialogTitle>Confirm</AlertDialogTitle><AlertDialogDescription>Are you sure?</AlertDialogDescription><AlertDialogCancel>Cancel</AlertDialogCancel><AlertDialogAction>Yes</AlertDialogAction></AlertDialogContent></AlertDialog>);
    await userEvent.click(screen.getByText("Delete"));
    expect(screen.getByText("Confirm")).toBeInTheDocument();
    expect(screen.getByText("Are you sure?")).toBeInTheDocument();
  });
});
