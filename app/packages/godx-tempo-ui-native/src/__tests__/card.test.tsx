import React from "react";
import { render, screen } from "@testing-library/react";
import { Card, CardHeader, CardTitle, CardDescription, CardContent, CardFooter } from "../components/card";
import { Text } from "../components/text";

describe("Card", () => {
  it("renders card with content", () => {
    render(
      <Card>
        <CardContent>
          <Text>Card body</Text>
        </CardContent>
      </Card>,
    );
    expect(screen.getByText("Card body")).toBeTruthy();
  });

  it("renders card with header and title", () => {
    render(
      <Card>
        <CardHeader>
          <CardTitle>My Title</CardTitle>
          <CardDescription>My Description</CardDescription>
        </CardHeader>
      </Card>,
    );
    expect(screen.getByText("My Title")).toBeTruthy();
    expect(screen.getByText("My Description")).toBeTruthy();
  });

  it("renders card with footer", () => {
    render(
      <Card>
        <CardFooter>
          <Text>Footer text</Text>
        </CardFooter>
      </Card>,
    );
    expect(screen.getByText("Footer text")).toBeTruthy();
  });
});
