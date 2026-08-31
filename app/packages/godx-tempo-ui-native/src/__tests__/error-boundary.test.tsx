import React from "react";
import { render, screen, fireEvent } from "@testing-library/react";
import { Text } from "react-native";
import { ErrorBoundary } from "../components/error-boundary";

function BrokenChild(): React.ReactElement {
  throw new Error("Test crash");
}

function GoodChild(): React.ReactElement {
  return <Text>All good</Text>;
}

describe("ErrorBoundary", () => {
  // Suppress console.error from React for expected errors
  const originalError = console.error;
  beforeEach(() => { console.error = jest.fn(); });
  afterEach(() => { console.error = originalError; });

  it("renders children when no error", () => {
    render(
      <ErrorBoundary>
        <GoodChild />
      </ErrorBoundary>,
    );
    expect(screen.getByText("All good")).toBeTruthy();
  });

  it("renders fallback UI when child throws", () => {
    render(
      <ErrorBoundary>
        <BrokenChild />
      </ErrorBoundary>,
    );
    expect(screen.getByText("Something went wrong")).toBeTruthy();
    expect(screen.getByText("Test crash")).toBeTruthy();
  });

  it("renders Try Again button on error", () => {
    render(
      <ErrorBoundary>
        <BrokenChild />
      </ErrorBoundary>,
    );
    expect(screen.getByText("Try Again")).toBeTruthy();
  });

  it("resets error state when Try Again is pressed", () => {
    let shouldThrow = true;
    function MaybeBreak(): React.ReactElement {
      if (shouldThrow) throw new Error("Boom");
      return <Text>Recovered</Text>;
    }

    render(
      <ErrorBoundary>
        <MaybeBreak />
      </ErrorBoundary>,
    );
    expect(screen.getByText("Something went wrong")).toBeTruthy();

    shouldThrow = false;
    fireEvent.click(screen.getByText("Try Again"));
    expect(screen.getByText("Recovered")).toBeTruthy();
  });
});
