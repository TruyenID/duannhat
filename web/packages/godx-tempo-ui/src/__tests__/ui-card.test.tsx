import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from "../card";

describe("Card", () => {
  it("renders card with content", () => {
    render(<Card><CardContent>Body</CardContent></Card>);
    expect(screen.getByText("Body")).toBeInTheDocument();
  });
  it("renders header with title and description", () => {
    render(<Card><CardHeader><CardTitle>Title</CardTitle><CardDescription>Desc</CardDescription></CardHeader></Card>);
    expect(screen.getByText("Title")).toBeInTheDocument();
    expect(screen.getByText("Desc")).toBeInTheDocument();
  });
  it("renders footer", () => {
    render(<Card><CardFooter>Footer</CardFooter></Card>);
    expect(screen.getByText("Footer")).toBeInTheDocument();
  });
  it("applies className", () => {
    const { container } = render(<Card className="custom">X</Card>);
    expect(container.firstChild).toHaveClass("custom");
  });
});
