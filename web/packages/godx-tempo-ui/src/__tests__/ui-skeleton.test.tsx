import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Skeleton } from "../skeleton";

describe("Skeleton", () => {
  it("renders", () => { const { container } = render(<Skeleton />); expect(container.firstChild).toBeInTheDocument(); });
  it("applies className", () => { const { container } = render(<Skeleton className="h-4 w-20" />); expect(container.firstChild).toHaveClass("h-4"); });
});
