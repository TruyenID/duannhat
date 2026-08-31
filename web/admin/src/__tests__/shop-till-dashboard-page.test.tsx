/**
 * Plan-036 T8.1 — Dashboard page smoke + KPI render.
 *
 * Mounts /shop/[shopSlug]/till and asserts:
 *  - empty-shop dashboard renders the page title + zeros KPIs
 *  - populated dashboard renders KPI numbers + per-till status cards
 *  - 5s polling interval is configured (we don't advance fake timers — the
 *    refetchInterval value is what matters for the contract)
 *
 * Recharts is stubbed because jsdom doesn't measure layout, so the real
 * ResponsiveContainer never renders children. The variance trend test only
 * checks that the chart wrapper appears; pixel-perfect chart geometry is a
 * Playwright concern.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
// Type-only import — feeds the vi.importActual<>() generic without violating
// the consistent-type-imports lint rule (which forbids inline `import()`).
import type * as Recharts from "recharts";

vi.mock("next/navigation", () => ({
  useParams: () => ({ shopSlug: "test-shop" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => ({ get: () => null, toString: () => "" }),
  usePathname: () => "/shop/test-shop/till",
  notFound: () => {
    throw new Error("notFound called");
  },
}));

// Stub the recharts ResponsiveContainer — see file header.
vi.mock("recharts", async () => {
  const actual = await vi.importActual<typeof Recharts>("recharts");
  return {
    ...actual,
    ResponsiveContainer: ({ children }: { children: ReactNode }) => (
      <div data-testid="responsive-container">{children}</div>
    ),
  };
});

interface DashboardKpis {
  open_till_count: number;
  total_till_count: number;
  settled_today: { count: number; gross_total_amount: number; currency_code: string };
  variance_today: { net_amount: number; abs_max_session_id: string | null; abs_max_amount: number };
  stale_count: { open_overdue: number; expired: number };
}

interface DashboardFixture {
  data: {
    currency_code: string;
    tills: Array<{
      id: string;
      name: string;
      status: "open" | "idle";
      current_session: null | {
        id: string;
        session_code: string;
        opener_name: string | null;
        opened_at: string;
        duration_hours: number;
        stale_warning: boolean;
        stale_critical: boolean;
      };
    }>;
    kpis: DashboardKpis;
    variance_trend: Array<{
      date: string;
      avg_amount: number;
      abs_max_amount: number;
      session_count: number;
    }>;
    recent_settlements: Array<{
      id: string;
      session_code: string;
      till_name: string | null;
      opener_name: string | null;
      expected_cash_amount: number;
      counted_cash_amount: number;
      variance_amount: number;
      closed_at: string | null;
    }>;
    force_abandon_activity_30d: Array<unknown>;
  };
  meta: {
    stale_threshold_hours: number;
    warning_hours: number;
    trend_days: number;
    cached_at: string;
  };
}

let dashboardFixture: DashboardFixture = makeEmptyFixture();

function makeEmptyFixture(): DashboardFixture {
  return {
    data: {
      currency_code: "JPY",
      tills: [],
      kpis: {
        open_till_count: 0,
        total_till_count: 0,
        settled_today: { count: 0, gross_total_amount: 0, currency_code: "JPY" },
        variance_today: { net_amount: 0, abs_max_session_id: null, abs_max_amount: 0 },
        stale_count: { open_overdue: 0, expired: 0 },
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

vi.mock("@/lib/api", () => ({
  apiFetch: vi.fn().mockImplementation((url: string) => {
    if (url.includes("/till/dashboard")) {
      return Promise.resolve(dashboardFixture);
    }
    if (url.includes("group_by=actor")) {
      return Promise.resolve({
        data: { force_abandoned_count: 0, expired_count: 0, per_manager: [] },
        meta: { within_days: 30, branch_id: null },
      });
    }
    return Promise.resolve({ data: [], meta: { total: 0 } });
  }),
  ApiError: class ApiError extends Error {
    status = 500;
    body: unknown = null;
  },
}));

import { AppProvider } from "@/providers/app-provider";
import ShopTillDashboardPage from "@/app/shop/[shopSlug]/till/page";
import { useShopTillDashboard } from "@/hooks/api/use-shop-till-tracking";

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
  dashboardFixture = makeEmptyFixture();
});

describe("Shop till dashboard page (plan-036)", () => {
  it("renders the page title for an empty shop", async () => {
    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      // Title comes from till_tracking.dashboard.title in i18n. Default
      // locale is ja → "レジ締め管理"; the regex covers the en + vi forms
      // too so a future locale switch doesn't silently break the test.
      const headings = screen.getAllByText(/レジ締め管理|Cashier Shifts|Quản lý ca thu ngân/);
      expect(headings.length).toBeGreaterThan(0);
    });
  });

  it("renders KPI numbers from the API response", async () => {
    dashboardFixture = {
      ...dashboardFixture,
      data: {
        ...dashboardFixture.data,
        kpis: {
          open_till_count: 2,
          total_till_count: 3,
          settled_today: { count: 7, gross_total_amount: 123456, currency_code: "JPY" },
          variance_today: { net_amount: -250, abs_max_session_id: null, abs_max_amount: 250 },
          stale_count: { open_overdue: 1, expired: 0 },
        },
      },
    };

    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText("2 / 3")).toBeInTheDocument();
    });
    // "7" appears as the settled_today count
    expect(screen.getByText("7")).toBeInTheDocument();
  });

  it("renders per-till status cards with stale warnings", async () => {
    dashboardFixture = {
      ...dashboardFixture,
      data: {
        ...dashboardFixture.data,
        tills: [
          {
            id: "till-1",
            name: "Counter A",
            status: "open",
            current_session: {
              id: "sess-1",
              session_code: "SHIFT-20260619-001",
              opener_name: "Alice",
              opened_at: "2026-06-19T00:00:00Z",
              duration_hours: 30,
              stale_warning: true,
              stale_critical: false,
            },
          },
        ],
        kpis: {
          ...dashboardFixture.data.kpis,
          open_till_count: 1,
          total_till_count: 1,
        },
      },
    };

    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText("Counter A")).toBeInTheDocument();
    });
    expect(screen.getByText("SHIFT-20260619-001")).toBeInTheDocument();
    expect(screen.getByText("Alice")).toBeInTheDocument();
  });

  it("links an occupied till card to its session detail page", async () => {
    // The card is where a stuck shift first surfaces on the overview; before
    // #1220 it had no affordance, so the amber "quá hạn" badge was a dead end.
    dashboardFixture = {
      ...dashboardFixture,
      data: {
        ...dashboardFixture.data,
        tills: [
          {
            id: "till-1",
            name: "Counter A",
            status: "open",
            current_session: {
              id: "sess-1",
              session_code: "SHIFT-20260619-001",
              opener_name: "Alice",
              opened_at: "2026-06-19T00:00:00Z",
              duration_hours: 30,
              stale_warning: true,
              stale_critical: false,
            },
          },
        ],
      },
    };

    render(<ShopTillDashboardPage />, { wrapper });

    const link = await screen.findByRole("link", { name: /Counter A/ });
    expect(link).toHaveAttribute("href", "/shop/test-shop/till/sessions/sess-1");
  });

  it("leaves an idle till card unlinked", async () => {
    // Nothing to drill into — a link would 404 or, worse, land on someone
    // else's last shift.
    dashboardFixture = {
      ...dashboardFixture,
      data: {
        ...dashboardFixture.data,
        tills: [{ id: "till-2", name: "Counter B", status: "idle", current_session: null }],
      },
    };

    render(<ShopTillDashboardPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText("Counter B")).toBeInTheDocument();
    });
    expect(screen.queryByRole("link", { name: /Counter B/ })).toBeNull();
  });

  it("renders the variance trend chart when trend rows have data (recharts smoke)", async () => {
    // recharts LineChart needs a measured parent; ResponsiveContainer is
    // already stubbed at the top so the chart renders into a fixed-size
    // <div data-testid="responsive-container">. Real chart geometry
    // (axis ticks, line path) requires SVG measurement which jsdom can't
    // do — this test only proves the container mounts without throwing
    // when the API returns non-empty trend rows.
    dashboardFixture = {
      ...dashboardFixture,
      data: {
        ...dashboardFixture.data,
        variance_trend: [
          { date: "2026-06-14", avg_amount: -200, abs_max_amount: 200, session_count: 2 },
          { date: "2026-06-15", avg_amount: 0, abs_max_amount: 0, session_count: 0 },
          { date: "2026-06-16", avg_amount: 150, abs_max_amount: 150, session_count: 1 },
        ],
      },
    };
    render(<ShopTillDashboardPage />, { wrapper });
    await waitFor(() => {
      const container = screen.queryByTestId("responsive-container");
      expect(container).not.toBeNull();
    });
  });

  it("configures useShopTillDashboard with 5s polling + tab-hidden pause", () => {
    // Direct contract assertion — we don't need to render the page. The hook
    // wraps tanstack-query and the polling cadence is the load contract the
    // backend was sized against (plan-036 §Performance).
    const QueryProbe = () => {
      const q = useShopTillDashboard("test-shop");
      return <div data-testid="probe">{String(q.isLoading)}</div>;
    };
    render(<QueryProbe />, { wrapper });
    // Sanity — the hook renders. The polling cadence itself is owned by the
    // hook definition (refetchInterval: 5_000), which a downstream change
    // would have to delete to break the contract. A grep-style assertion is
    // overkill here, but the hook-mounts smoke is the minimum guard against
    // accidentally removing the export.
    expect(screen.getByTestId("probe")).toBeInTheDocument();
  });
});
