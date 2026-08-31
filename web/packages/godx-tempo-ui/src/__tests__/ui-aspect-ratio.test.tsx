import React from "react";
import { render } from "@testing-library/react";
import { describe, it, expect } from "vitest";
import { AspectRatio } from "../aspect-ratio";

describe("AspectRatio", () => {
  it("renders children", () => {
    const { getByText } = render(<AspectRatio ratio={16/9}><div>Content</div></AspectRatio>);
    expect(getByText("Content")).toBeInTheDocument();
  });
});
