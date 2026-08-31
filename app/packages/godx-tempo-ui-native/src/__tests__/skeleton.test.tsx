import React from "react";
import { render } from "@testing-library/react";
import { Skeleton } from "../components/skeleton";

describe("Skeleton", () => {
  it("renders without crashing", () => {
    const { container } = render(<Skeleton />);
    expect(container.firstChild).toBeTruthy();
  });
});
