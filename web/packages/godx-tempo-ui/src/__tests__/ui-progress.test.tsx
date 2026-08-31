import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Progress } from "../progress";

describe("Progress", () => {
  it("renders", () => { const { container } = render(<Progress value={50} />); expect(container.firstChild).toBeInTheDocument(); });
  it("renders progressbar role", () => { render(<Progress value={75} />); const el = document.querySelector('[role="progressbar"]'); expect(el).toBeInTheDocument(); });
});
