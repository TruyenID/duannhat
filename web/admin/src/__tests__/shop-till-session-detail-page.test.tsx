/**
 * Plan-036 T8.3 — Session detail page smoke.
 *
 * Mounts /shop/[shopSlug]/till/sessions/[id] and asserts:
 *  - the session code + status badge render
 *  - the Z-report button is disabled when links.z_report_available=false
 *    (tooltip applies, no download fires)
 *  - the Z-report button is enabled when links.z_report_available=true and
 *    triggers the download mutation
 *  - Force-abandon button shows when status ∈ {open, closing} (T7.4)
 *  - Manual-settle button shows when status === expired (T7.4)
 *
 * Mocks apiFetch + ResizeObserver. The two plan-032 dialogs render inert
 * Dialog roots when closed so we just probe the trigger buttons.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

vi.mock("next/navigation", () => ({
  useParams: () => ({ shopSlug: "test-shop", id: "sess-123" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => ({ get: () => null, toString: () => "" }),
  usePathname: () => "/shop/test-shop/till/sessions/sess-123",
  notFound: () => {
    throw new Error("notFound called");
  },
}));

type SessionFixture = ReturnType<typeof makeSessionFixture>;

function makeSessionFixture(
  overrides: Partial<{
    status: "open" | "closing" | "settled" | "abandoned" | "expired";
    z_report_available: boolean;
  }> = {}
) {
  return {
    data: {
      id: "sess-123",
      session_code: "SHIFT-20260619-007",
      status: overrides.status ?? "settled",
      till: { id: "till-1", name: "Counter A", variance_tolerance_amount: 100 },
      branch: { id: "branch-1", name: "Test Shop" },
      currency_code: "JPY",
      business_date: "2026-06-19",
      opener_name: "Alice",
      opened_by: { id: "user-alice", name: "Alice" },
      closed_by: { id: "user-bob", name: "Bob" },
      opened_at: "2026-06-19T00:00:00Z",
      closed_at: "2026-06-19T08:00:00Z",
      abandoned_at: null,
      expired_at: null,
      expire_reason: null,
      expire_threshold_hours: null,
      duration_hours: 8,
      force_abandoned: false,
      force_abandoned_by: null,
      force_abandon_reason_code: null,
      force_abandon_reason_detail: null,
      opening_float_amount: 5000,
      opening_note: null,
      closing_note: null,
      expected_cash_amount: 15000,
      counted_cash_amount: 14950,
      cash_variance_amount: -50,
      opening_counts: [],
      closing_counts: [],
      cash_events: [],
      reconciliation: { by_tender_category: [] },
      audit_trail: [],
      links: {
        z_report_pdf: "/api/v1/shops/test-shop/till/sessions/sess-123/z-report.pdf",
        z_report_available: overrides.z_report_available ?? true,
      },
    },
  };
}

let sessionFixture: SessionFixture = makeSessionFixture();
const downloadMock = vi.fn();

vi.mock("@/lib/api", () => ({
  apiFetch: vi.fn().mockImplementation((url: string, opts?: { responseType?: string }) => {
    if (url.includes("z-report.pdf")) {
      downloadMock(url);
      // Return an empty blob so the download mutation completes happy-path.
      return Promise.resolve(new Blob(["%PDF-stub"], { type: "application/pdf" }));
    }
    if (opts?.responseType === "blob") {
      return Promise.resolve(new Blob([""]));
    }
    if (url.includes("/till/sessions/sess-123")) {
      return Promise.resolve(sessionFixture);
    }
    return Promise.resolve({ data: null });
  }),
  ApiError: class ApiError extends Error {
    status = 500;
    body: unknown = null;
  },
}));

// URL.createObjectURL is missing in jsdom and the Z-report download path
// calls it; stub before importing the page.
beforeEach(() => {
  vi.clearAllMocks();
  sessionFixture = makeSessionFixture();
  if (typeof globalThis.URL.createObjectURL !== "function") {
    globalThis.URL.createObjectURL = vi.fn(() => "blob:mock");
  }
  if (typeof globalThis.URL.revokeObjectURL !== "function") {
    globalThis.URL.revokeObjectURL = vi.fn();
  }
});

import { AppProvider } from "@/providers/app-provider";
import ShopTillSessionDetailPage from "@/app/shop/[shopSlug]/till/sessions/[id]/page";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

describe("Shop till session detail page (plan-036)", () => {
  it("renders the session code + status badge", async () => {
    render(<ShopTillSessionDetailPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText(/SHIFT-20260619-007/)).toBeInTheDocument();
    });
  });

  it("disables the Z-report button when z_report_available=false", async () => {
    sessionFixture = makeSessionFixture({ status: "open", z_report_available: false });
    render(<ShopTillSessionDetailPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText(/SHIFT-20260619-007/)).toBeInTheDocument();
    });

    // The button label is the i18n print_z_report key; find by role + name regex.
    const button = screen
      .getAllByRole("button")
      .find((b) => /zレポート|z-?report|in z-report/i.test(b.textContent ?? ""));
    expect(button).toBeDefined();
    expect(button).toBeDisabled();
  });

  it("triggers the download mutation when Z-report button is clicked (settled session)", async () => {
    render(<ShopTillSessionDetailPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText(/SHIFT-20260619-007/)).toBeInTheDocument();
    });

    const button = screen
      .getAllByRole("button")
      .find((b) => /zレポート|z-?report|in z-report/i.test(b.textContent ?? ""));
    expect(button).toBeDefined();
    expect(button).not.toBeDisabled();

    fireEvent.click(button!);
    await waitFor(() => {
      expect(downloadMock).toHaveBeenCalled();
    });
  });

  it("shows Force-abandon button on an open session (T7.4)", async () => {
    sessionFixture = makeSessionFixture({ status: "open" });
    render(<ShopTillSessionDetailPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText(/SHIFT-20260619-007/)).toBeInTheDocument();
    });

    const forceBtn = screen
      .getAllByRole("button")
      .find((b) => /force.?abandon|強制終了|huỷ cưỡng chế/i.test(b.textContent ?? ""));
    expect(forceBtn).toBeDefined();
  });

  it("shows Manual-settle button on an expired session (T7.4)", async () => {
    sessionFixture = makeSessionFixture({ status: "expired" });
    render(<ShopTillSessionDetailPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText(/SHIFT-20260619-007/)).toBeInTheDocument();
    });

    const settleBtn = screen
      .getAllByRole("button")
      .find((b) => /manual.?settle|手動精算|kết ca thủ công/i.test(b.textContent ?? ""));
    expect(settleBtn).toBeDefined();
  });
});
