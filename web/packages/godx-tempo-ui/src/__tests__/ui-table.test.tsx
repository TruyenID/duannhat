import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "../table";

describe("Table", () => {
  it("renders table with rows", () => {
    render(<Table><TableHeader><TableRow><TableHead>Name</TableHead></TableRow></TableHeader><TableBody><TableRow><TableCell>John</TableCell></TableRow></TableBody></Table>);
    expect(screen.getByText("Name")).toBeInTheDocument();
    expect(screen.getByText("John")).toBeInTheDocument();
  });
});
