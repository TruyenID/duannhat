import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { ApiError } from "@/lib/api";
import { AppProvider } from "@/providers/app-provider";

const apiFetchMock = vi.fn();
const logoutMock = vi.fn();

vi.mock("@/lib/api", () => {
  class MockApiError extends Error {
    constructor(
      public status: number,
      public body: Record<string, unknown>
    ) {
      super((body.message as string) || `API Error ${status}`);
    }
  }

  return {
    ApiError: MockApiError,
    apiFetch: (...args: unknown[]) => apiFetchMock(...args),
  };
});

vi.mock("@/lib/auth", () => ({
  logout: () => logoutMock(),
}));

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

// Static import: `vi.mock` factories are hoisted above it, so the mocks are
// already registered. A per-test `await import("./page")` charged this file's
// (heavy) transform to the FIRST test's timeout budget, which made it the only
// test in the suite that failed under full-suite parallelism (#1184).
import SelectContextPage from "./page";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  apiFetchMock.mockReset();
  logoutMock.mockReset();
  localStorage.clear();
});

describe("SelectContextPage authorization bootstrap", () => {
  it("renders an explicit access-denied screen when context returns 403", async () => {
    apiFetchMock.mockRejectedValue(
      new ApiError(403, { message: "This account cannot access Tempo Admin." })
    );

    render(<SelectContextPage />, { wrapper });

    expect(await screen.findByRole("heading", { name: "Access denied" })).toBeInTheDocument();
    expect(apiFetchMock).toHaveBeenCalledTimes(1);

    screen.getByRole("button", { name: "Sign out" }).click();
    expect(logoutMock).toHaveBeenCalledOnce();
  });

  it("treats an authenticated account with no workspaces as denied", async () => {
    apiFetchMock.mockResolvedValue({
      user: { id: "user-1", name: "Staff", email: "staff@example.com" },
      brand_count: 0,
      shop_count: 0,
    });

    render(<SelectContextPage />, { wrapper });

    expect(await screen.findByRole("heading", { name: "Access denied" })).toBeInTheDocument();
    expect(screen.queryByText("Select workspace")).not.toBeInTheDocument();
    expect(apiFetchMock).toHaveBeenCalledTimes(1);
  });

  it("shows retry recovery for a transient bootstrap failure", async () => {
    apiFetchMock
      .mockRejectedValueOnce(new ApiError(500, { message: "Server error" }))
      .mockResolvedValue({
        user: { id: "user-1", name: "Admin", email: "admin@example.com" },
        brand_count: 0,
        shop_count: 0,
      });

    render(<SelectContextPage />, { wrapper });

    const retry = await screen.findByRole("button", { name: "Retry" });
    retry.click();

    await waitFor(() => expect(apiFetchMock).toHaveBeenCalledTimes(2));
    expect(await screen.findByRole("heading", { name: "Access denied" })).toBeInTheDocument();
  });
});
