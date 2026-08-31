import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { Separator } from "../separator";

describe("Separator", () => {
  it("renders horizontal by default", () => { render(<Separator />); expect(document.querySelector('[data-slot="separator-root"]')).toBeInTheDocument(); });
  it("renders vertical", () => { render(<Separator orientation="vertical" />); expect(document.querySelector('[data-slot="separator-root"]')).toBeInTheDocument(); });
});
