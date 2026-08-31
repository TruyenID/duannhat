import { render, screen } from "@testing-library/react";
import { describe, it, expect, beforeAll, afterAll, vi } from "vitest";
import { ErrorBoundary } from "../error-boundary";

function Boom(): React.ReactElement {
  throw new Error("test boom");
}

// Suppress noisy console.error from ErrorBoundary's componentDidCatch
let consoleSpy: ReturnType<typeof vi.spyOn>;
beforeAll(() => {
  consoleSpy = vi.spyOn(console, "error").mockImplementation(() => {});
});
afterAll(() => {
  consoleSpy.mockRestore();
});

describe("ErrorBoundary", () => {
  it("renders children when no error", () => {
    render(
      <ErrorBoundary>
        <div data-testid="child">ok</div>
      </ErrorBoundary>,
    );
    expect(screen.getByTestId("child")).toBeInTheDocument();
  });

  it("renders recovery UI when child throws", () => {
    render(
      <ErrorBoundary>
        <Boom />
      </ErrorBoundary>,
    );
    expect(screen.getByRole("alert")).toBeInTheDocument();
    expect(screen.getByText(/Something went wrong/)).toBeInTheDocument();
    expect(screen.getByText(/test boom/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: /Reload/i })).toBeInTheDocument();
  });
});
