import React from "react";
import { render, screen } from "@testing-library/react";
import { Badge } from "../components/badge";
import { Text } from "../components/text";

describe("Badge", () => {
  it("renders badge with text", () => {
    render(
      <Badge>
        <Text>Active</Text>
      </Badge>,
    );
    expect(screen.getByText("Active")).toBeTruthy();
  });

  it("renders with default variant", () => {
    render(
      <Badge variant="default">
        <Text>Default</Text>
      </Badge>,
    );
    expect(screen.getByText("Default")).toBeTruthy();
  });

  it("renders with secondary variant", () => {
    render(
      <Badge variant="secondary">
        <Text>Secondary</Text>
      </Badge>,
    );
    expect(screen.getByText("Secondary")).toBeTruthy();
  });

  it("renders with destructive variant", () => {
    render(
      <Badge variant="destructive">
        <Text>Error</Text>
      </Badge>,
    );
    expect(screen.getByText("Error")).toBeTruthy();
  });
});
