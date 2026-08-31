import React from "react";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { describe, it, expect } from "vitest";
import { Tabs, TabsList, TabsTrigger, TabsContent } from "../tabs";

describe("Tabs", () => {
  it("renders tab triggers", () => {
    render(<Tabs defaultValue="a"><TabsList><TabsTrigger value="a">Tab A</TabsTrigger><TabsTrigger value="b">Tab B</TabsTrigger></TabsList><TabsContent value="a">Content A</TabsContent><TabsContent value="b">Content B</TabsContent></Tabs>);
    expect(screen.getByText("Tab A")).toBeInTheDocument();
    expect(screen.getByText("Tab B")).toBeInTheDocument();
  });
  it("shows active tab content", () => {
    render(<Tabs defaultValue="a"><TabsList><TabsTrigger value="a">A</TabsTrigger></TabsList><TabsContent value="a">Active</TabsContent></Tabs>);
    expect(screen.getByText("Active")).toBeInTheDocument();
  });
});
