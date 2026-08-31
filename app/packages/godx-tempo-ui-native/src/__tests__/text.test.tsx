import React from "react";
import { render, screen } from "@testing-library/react";
import { Text } from "../components/text";

describe("Text", () => {
  it("renders text content", () => {
    render(<Text>Hello</Text>);
    expect(screen.getByText("Hello")).toBeTruthy();
  });

  it("renders with variant h1", () => {
    render(<Text variant="h1">Title</Text>);
    expect(screen.getByText("Title")).toBeTruthy();
  });

  it("renders with variant muted", () => {
    render(<Text variant="muted">Subtitle</Text>);
    expect(screen.getByText("Subtitle")).toBeTruthy();
  });

  it("renders children elements", () => {
    render(
      <Text>
        Hello <Text>World</Text>
      </Text>,
    );
    expect(screen.getByText("World")).toBeTruthy();
  });
});
