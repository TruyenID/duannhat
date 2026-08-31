/**
 * /shop/[shopSlug]/till — consolidated till page (#1005).
 *
 * The former standalone sessions list (Lịch sử ca + Ca treo) is now three tabs
 * on /till: Tổng quan · Lịch sử ca · Ca treo. This suite asserts:
 *   - all three tabs render
 *   - the default (Tổng quan) tab drives the dashboard endpoint
 *   - ?tab=history mounts the history tab and hits the sessions list endpoint
 *   - ?tab=stale mounts the Ca treo tab and hits the stale endpoint
 *   - the plan-031 ?filter=open_overdue deep link lands on Ca treo with the
 *     stale filter preselected
 *
 * The canonical /till/sessions list route is now a redirect stub and the detail
 * route /till/sessions/[id] is unchanged, so neither is exercised here.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, within } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import type * as Recharts from "recharts";

const mockSearchParams = { current: new URLSearchParams() };
const apiCalls: string[] = [];

vi.mock("next/navigation", () => ({
  useParams: () => ({ shopSlug: "test-shop" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => ({
    get: (k: string) => mockSearchParams.current.get(k),
    toString: () => mockSearchParams.current.toString(),
  }),
  usePathname: () => "/shop/test-shop/till",
  notFound: () => {
    throw new Error("notFound called");
  },
}));

// jsdom can't measure layout, so ResponsiveContainer never renders children —
// stub it so the overview tab's variance chart mounts without throwing.
vi.mock("recharts", async () => {
  const actual = await vi.importActual<typeof Recharts>("recharts");
  return {
    ...actual,
    ResponsiveContainer: ({ children }: { children: ReactNode }) => (
      <div data-testid="responsive-container">{children}</div>
    ),
  };
});

function makeDashboardFixture(staleCount = { open_overdue: 0, expired: 0 }) {
  return {
    data: {
      currency_code: "JPY",
      tills: [],
      kpis: {
        open_till_count: 0,
        total_till_count: 0,
        settled_today: { count: 0, gross_total_amount: 0, currency_code: "JPY" },
        variance_today: { net_amount: 0, abs_max_session_id: null, abs_max_amount: 0 },
        stale_count: staleCount,
      },
      variance_trend: [],
      recent_settlements: [],
      force_abandon_activity_30d: [],
    },
    meta: {
      stale_threshold_hours: 48,
      warning_hours: 24,
      trend_days: 14,
      cached_at: "2026-06-19T08:30:00Z",
    },
  };
}

// Mutable so a test can move the KPI breakdown or fail the dashboard outright.
// Reset in beforeEach.
let dashboardFixture = makeDashboardFixture();
let dashboardFails = false;

vi.mock("@/lib/api", () => ({
  apiFetch: vi.fn().mockImplementation((url: string) => {
    apiCalls.push(url);
    if (url.includes("/till/dashboard")) {
      return dashboardFails
        ? Promise.reject(Object.assign(new Error("boom"), { status: 500 }))
        : Promise.resolve(dashboardFixture);
    }
    if (url.includes("group_by=actor")) {
      return Promise.resolve({
        data: { force_abandoned_count: 0, expired_count: 0, per_manager: [] },
        meta: { within_days: 30, branch_id: null },
      });
    }
    if (url.includes("/till/sessions/stale")) {
      // The real endpoint reports the threshold that selected the rows: the 24h
      // overdue band for open_overdue, the 48h reaper cutoff otherwise (#1220).
      const isOverdue = url.includes("filter=open_overdue");
      return Promise.resolve({
        data: [],
        meta: { threshold_hours: isOverdue ? 24 : 48, total: 0 },
      });
    }
    // History list (and any fallback).
    return Promise.resolve({
      data: [],
      meta: {
        current_page: 1,
        per_page: 25,
        total: 0,
        last_page: 1,
        from: "2026-06-12",
        to: "2026-06-19",
        applied_filters: {},
      },
    });
  }),
  ApiError: class ApiError extends Error {
    status = 500;
    body: unknown = null;
  },
}));

import { AppProvider } from "@/providers/app-provider";
import ShopTillDashboardPage from "@/app/shop/[shopSlug]/till/page";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  mockSearchParams.current = new URLSearchParams();
  apiCalls.length = 0;
  dashboardFixture = makeDashboardFixture();
  dashboardFails = false;
});

describe("Consolidated /till page (#1005)", () => {
  it("renders all three till tabs", async () => {
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getAllByText(/概要|Overview|Tổng quan/i).length).toBeGreaterThan(0);
    });
    expect(screen.getAllByText(/シフト履歴|Shift History|Lịch sử ca/i).length).toBeGreaterThan(0);
    expect(screen.getAllByText(/ぶら下がりシフト|Stale Shifts|Ca treo/i).length).toBeGreaterThan(0);
  });

  it("drives the dashboard endpoint on the default Tổng quan tab", async () => {
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      expect(apiCalls.some((u) => u.includes("/till/dashboard"))).toBe(true);
    });
  });

  it("?tab=history mounts the history tab and hits the sessions list endpoint", async () => {
    mockSearchParams.current = new URLSearchParams("?tab=history");
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      const listCall = apiCalls.find((u) => u.includes("/till/sessions?"));
      expect(listCall).toBeDefined();
    });
  });

  it("?tab=stale mounts the Ca treo tab and hits the stale endpoint", async () => {
    mockSearchParams.current = new URLSearchParams("?tab=stale");
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      const staleCall = apiCalls.find((u) => u.includes("/till/sessions/stale"));
      expect(staleCall).toBeDefined();
    });
  });

  it("plan-031 ?filter=open_overdue lands on Ca treo with the filter preselected", async () => {
    mockSearchParams.current = new URLSearchParams("?filter=open_overdue");
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      const staleCall = apiCalls.find(
        (u) => u.includes("/till/sessions/stale") && u.includes("filter=open_overdue")
      );
      expect(staleCall).toBeDefined();
    });
  });
});

/*
 * #1220 — the KPI badge and the Ca treo list must agree, and the tab must open on
 * the bucket that actually holds rows. Reported as "badge shows 1 but the tab is
 * empty": the count lived in `expired` while the panel always opened `open_overdue`.
 */
describe("Ca treo tab lands on the populated bucket (#1220)", () => {
  // The count badge lives inside the trigger, so the accessible name is
  // "<label><count>" — match on the label prefix, not an anchored full string.
  const staleTab = () => screen.getByRole("tab", { name: /^(期限切れ|Expired|Đã hết hạn)/ });
  const overdueTabName = /^(期限超過|Overdue \(still open\)|Ca quá hạn)/;

  it("opens on Đã hết hạn when the whole count is in the expired bucket", async () => {
    dashboardFixture = makeDashboardFixture({ open_overdue: 0, expired: 1 });
    mockSearchParams.current = new URLSearchParams("?tab=stale");
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      expect(staleTab()).toHaveAttribute("data-state", "active");
    });

    // Scope to the sub-tab: "1" also renders in the main Ca treo tab badge.
    expect(within(staleTab()).getByText("1")).toBeInTheDocument();

    // The list follows the sub-tab. Assert the LAST stale call, not the absence of
    // an open_overdue one — the first render legitimately fetches the default bucket.
    await waitFor(() => {
      const staleCalls = apiCalls.filter((u) => u.includes("/till/sessions/stale?"));
      expect(staleCalls.at(-1)).toContain("filter=expired");
    });
  });

  it("stays on Ca quá hạn when that bucket has rows", async () => {
    dashboardFixture = makeDashboardFixture({ open_overdue: 2, expired: 1 });
    mockSearchParams.current = new URLSearchParams("?tab=stale");
    render(<ShopTillDashboardPage />, { wrapper });

    const overdueTab = await screen.findByRole("tab", { name: overdueTabName });
    await waitFor(() => {
      expect(overdueTab).toHaveAttribute("data-state", "active");
    });
    expect(within(overdueTab).getByText("2")).toBeInTheDocument();
  });

  it("honours a ?filter= deep link over the auto-landing", async () => {
    // Settings links here to show blocking shifts; an explicit filter must win even
    // when the counts would have pointed elsewhere.
    dashboardFixture = makeDashboardFixture({ open_overdue: 0, expired: 3 });
    mockSearchParams.current = new URLSearchParams("?tab=stale&filter=open_overdue");
    render(<ShopTillDashboardPage />, { wrapper });

    const overdueTab = await screen.findByRole("tab", { name: overdueTabName });
    await waitFor(() => {
      expect(overdueTab).toHaveAttribute("data-state", "active");
    });
  });

  it("?tab=history&status= seeds the history status chips (#1221)", async () => {
    // The currency guard links here. The 409 it reacts to fires for an open
    // shift of ANY age, so status — not the overdue band — is the right axis.
    mockSearchParams.current = new URLSearchParams("?tab=history&status=open,closing");
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      const listCall = apiCalls.find((u) => u.includes("/till/sessions?"));
      expect(listCall).toBeDefined();
      expect(listCall).toContain("status%5B%5D=open");
      expect(listCall).toContain("status%5B%5D=closing");
    });
  });

  it("drops unknown ?status= values instead of sending them (#1221)", async () => {
    // The backend 422s an invalid status[], which would turn a mistyped link
    // into an error screen rather than an unfiltered list.
    mockSearchParams.current = new URLSearchParams("?tab=history&status=open,bogus");
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      const listCall = apiCalls.find((u) => u.includes("/till/sessions?"));
      expect(listCall).toBeDefined();
      expect(listCall).toContain("status%5B%5D=open");
      expect(listCall).not.toContain("bogus");
    });
  });

  it("keeps the Ca treo tab usable when the dashboard endpoint fails", async () => {
    // The stale list is a different endpoint. Gating the panel on the dashboard
    // would strand the manager with no way to triage stuck shifts.
    dashboardFails = true;
    mockSearchParams.current = new URLSearchParams("?tab=stale");
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      expect(apiCalls.some((u) => u.includes("/till/sessions/stale"))).toBe(true);
    });
    expect(screen.getByRole("tab", { name: overdueTabName })).toBeInTheDocument();
  });
});
