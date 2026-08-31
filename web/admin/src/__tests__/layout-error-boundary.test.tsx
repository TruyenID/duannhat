/**
 * Bug 1 regression: layout crash on 5xx / network error.
 *
 * Before the fix, BrandLayout and ShopLayout called `throw error` for non-404/403
 * errors, crashing the entire React tree with a blank page. After the fix,
 * a user-friendly recovery UI is rendered instead.
 */
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { Component, type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import * as api from "@/lib/api";

// ── helpers ───────────────────────────────────────────────────────────────────

// Static imports: `vi.mock` factories are hoisted above them, so the mocks are
// already registered. Per-test `await import(...)` charged each layout's heavy
// transform to that test's timeout budget, making the file flaky under
// full-suite parallelism (#1184).
import BrandLayout from "@/app/hq/[brandSlug]/layout";
import ShopLayout from "@/app/shop/[shopSlug]/layout";

/** Simple error boundary to catch any throw from the layout under test. */
class CrashBoundary extends Component<
  { children: ReactNode },
  { crashed: boolean; message: string }
> {
  state = { crashed: false, message: "" };
  static getDerivedStateFromError(err: unknown) {
    return { crashed: true, message: String(err) };
  }
  render() {
    if (this.state.crashed) {
      return <div data-testid="crashed">CRASHED: {this.state.message}</div>;
    }
    return this.props.children;
  }
}

function createWrapper() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return function Wrapper({ children }: { children: ReactNode }) {
    return (
      <QueryClientProvider client={queryClient}>
        <AppProvider>{children}</AppProvider>
      </QueryClientProvider>
    );
  };
}

// Mock next/navigation (useParams, notFound)
vi.mock("next/navigation", () => ({
  useParams: () => ({ brandSlug: "test-brand", shopSlug: "test-shop" }),
  notFound: () => {
    throw new Error("NEXT_NOT_FOUND");
  },
}));

// Mock heavy layout sub-components so we only test the error-handling path
vi.mock("@/components/layout/loading-shell", () => ({
  LoadingShell: () => <div data-testid="loading-shell" />,
}));
vi.mock("@/components/layout/page-shell", () => ({
  PageShell: ({ children }: { children: ReactNode }) => (
    <div data-testid="page-shell">{children}</div>
  ),
}));
vi.mock("@/providers/tenant-theme-provider", () => ({
  TenantThemeProvider: ({ children }: { children: ReactNode }) => <>{children}</>,
}));

// ── tests ─────────────────────────────────────────────────────────────────────

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.clear();
});

describe("BrandLayout — 5xx error handling", () => {
  it("renders a user-friendly error UI instead of crashing on 5xx", async () => {
    // apiFetch throws a 500 ApiError
    vi.spyOn(api, "apiFetch").mockRejectedValue(
      new api.ApiError(500, { message: "Internal Server Error" })
    );

    // Lazy import so the module-level mock is in place first
    const Wrapper = createWrapper();
    render(
      <Wrapper>
        <CrashBoundary>
          <BrandLayout>
            <div data-testid="child" />
          </BrandLayout>
        </CrashBoundary>
      </Wrapper>
    );

    // Must NOT have crashed the React tree
    await waitFor(() => {
      expect(screen.queryByTestId("crashed")).toBeNull();
    });

    // Must show a user-facing error message
    await waitFor(() => {
      // The text may be in ja (default locale) or via i18n key fallback
      const errorEl =
        screen.queryByText(/読み込みに失敗しました|error_loading|Error loading/i) ||
        screen.queryByRole("button", { name: /再試行|retry/i });
      expect(errorEl).not.toBeNull();
    });
  });

  it("calls notFound() for 404 errors (existing behaviour preserved)", async () => {
    vi.spyOn(api, "apiFetch").mockRejectedValue(new api.ApiError(404, { message: "Not Found" }));

    const Wrapper = createWrapper();
    render(
      <Wrapper>
        <CrashBoundary>
          <BrandLayout>
            <div data-testid="child" />
          </BrandLayout>
        </CrashBoundary>
      </Wrapper>
    );

    // notFound() throws internally — our CrashBoundary catches it with "NEXT_NOT_FOUND"
    await waitFor(() => {
      expect(screen.queryByTestId("crashed")).not.toBeNull();
    });

    expect(screen.getByTestId("crashed").textContent).toContain("NEXT_NOT_FOUND");
  });
});

describe("ShopLayout — 5xx error handling", () => {
  it("renders a user-friendly error UI instead of crashing on 5xx", async () => {
    vi.spyOn(api, "apiFetch").mockRejectedValue(
      new api.ApiError(500, { message: "Internal Server Error" })
    );

    const Wrapper = createWrapper();
    render(
      <Wrapper>
        <CrashBoundary>
          <ShopLayout>
            <div data-testid="child" />
          </ShopLayout>
        </CrashBoundary>
      </Wrapper>
    );

    // Must NOT have crashed the React tree
    await waitFor(() => {
      expect(screen.queryByTestId("crashed")).toBeNull();
    });

    // Must show a user-facing error message
    await waitFor(() => {
      const errorEl =
        screen.queryByText(/読み込みに失敗しました|error_loading|Error loading/i) ||
        screen.queryByRole("button", { name: /再試行|retry/i });
      expect(errorEl).not.toBeNull();
    });
  });
});
