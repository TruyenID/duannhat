import React from "react";
import { render, screen } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { PageContainer } from "../page-container";

describe("PageContainer", () => {
  it("renders children", () => {
    render(<PageContainer>Page content</PageContainer>);
    expect(screen.getByText("Page content")).toBeInTheDocument();
  });
});
