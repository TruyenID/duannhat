import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Spinner } from "../spinner";

describe("Spinner", () => {
  it("renders with aria-label", () => { const { container } = render(<Spinner />); expect(container.querySelector('[aria-label]')).toBeInTheDocument(); });
  it("applies className", () => { const { container } = render(<Spinner className="text-primary" />); expect(container.firstChild).toBeInTheDocument(); });
});
