import React from "react";
import { render } from "@testing-library/react";
import { Separator } from "../components/separator";

describe("Separator", () => {
  it("renders without crashing", () => {
    const { container } = render(<Separator />);
    expect(container.firstChild).toBeTruthy();
  });
});
