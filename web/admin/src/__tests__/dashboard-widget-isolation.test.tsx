/**
 * TC-DASH-111: a single failing dashboard widget API must not take down the page.
 *
 * Each dashboard widget owns an independent useQuery + isError branch. When one
 * endpoint (revenue-chart) returns 500, only that widget shows the error state;
 * every other widget still renders its data.
 */
import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import type * as Recharts from "recharts";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import * as api from "@/lib/api";

// next/navigation — page reads brandSlug from params and a router for navigation.
vi.mock("next/navigation", () => ({
  useParams: () => ({ brandSlug: "beto-kitchen" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn(), prefetch: vi.fn() }),
}));

// Recharts ResponsiveContainer needs a measured parent in jsdom — stub it.
vi.mock("recharts", async () => {
  const actual = await vi.importActual<typeof Recharts>("recharts");
  return {
    ...actual,
    ResponsiveContainer: ({ children }: { children: ReactNode }) => (
      <div data-testid="responsive-container">{children}</div>
    ),
  };
});

// Static import: `vi.mock` factories are hoisted above it, so the mocks are
// already registered. A per-test `await import(...)` charged this page's
// (heavy) transform to the first test's timeout budget, making the file flaky
// under full-suite parallelism (#1184).
import DashboardPage from "@/app/hq/[brandSlug]/dashboard/page";

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

/** Route apiFetch by URL: revenue-chart → 500, everything else → minimal happy data. */
function mockApiByEndpoint() {
  return vi.spyOn(api, "apiFetch").mockImplementation((path: string) => {
    if (path.includes("/revenue-chart")) {
      return Promise.reject(new api.ApiError(500, { message: "Internal Server Error" }));
    }
    if (path.includes("/kpis")) {
      return Promise.resolve({
        data: {
          revenue: { value: 913340, delta_pct: 20 },
          orders: { value: 249, delta_pct: 15 },
          products: { value: 60, delta_pct: 100 },
          shops: { value: 7, delta_pct: 0 },
        },
      }) as Promise<never>;
    }
    if (path.includes("/category-sales")) {
      return Promise.resolve({
        data: [{ category_id: "c1", category_name: "Main", revenue: 555601, percentage: 59.9 }],
      }) as Promise<never>;
    }
    if (path.includes("/shop-performance")) {
      return Promise.resolve({
        data: [
          {
            branch_id: "b1",
            branch_slug: "sjk",
            branch_name: "新宿店",
            revenue: 120632,
            target: 103721,
          },
        ],
      }) as Promise<never>;
    }
    if (path.includes("/top-products")) {
      return Promise.resolve({
        data: [
          {
            product_id: "p1",
            product_name: "Smoothie",
            category_name: "Drink",
            sold: 124,
            revenue: 58520,
            trend: "up",
          },
        ],
      }) as Promise<never>;
    }
    if (path.includes("/recent-orders")) {
      return Promise.resolve({
        data: [
          {
            id: "o1",
            order_code: "ORD-001",
            table_code: "T1",
            items_count: 3,
            total_amount: 4500,
            status: "completed",
            created_at: "2026-06-01T00:00:00+00:00",
          },
        ],
      }) as Promise<never>;
    }
    return Promise.resolve({ data: [] }) as Promise<never>;
  });
}

/** Brand-with-no-data: KPIs all zero, every list empty. Mirrors a freshly created brand. */
function mockApiEmpty() {
  return vi.spyOn(api, "apiFetch").mockImplementation((path: string) => {
    if (path.includes("/kpis")) {
      return Promise.resolve({
        data: {
          revenue: { value: 0, delta_pct: 0 },
          orders: { value: 0, delta_pct: 0 },
          products: { value: 0, delta_pct: 0 },
          shops: { value: 0, delta_pct: 0 },
        },
      }) as Promise<never>;
    }
    // revenue-chart, category-sales, shop-performance, top-products, recent-orders
    return Promise.resolve({ data: [] }) as Promise<never>;
  });
}

beforeEach(() => {
  vi.clearAllMocks();
  localStorage.clear();
});

describe("TC-DASH-111 — dashboard widget isolation", () => {
  it("shows the error state only for the failed widget; others still render", async () => {
    mockApiByEndpoint();

    const Wrapper = createWrapper();
    render(
      <Wrapper>
        <DashboardPage />
      </Wrapper>
    );

    // Default locale is ja, so SectionError renders the Japanese error string.
    const ERROR_TEXT = "読み込みに失敗しました";
    const RETRY_TEXT = "再試行";

    // The failed revenue-chart widget shows the SectionError.
    await waitFor(
      () => {
        expect(screen.getByText(ERROR_TEXT)).toBeInTheDocument();
      },
      { timeout: 3000 }
    );

    // Other widgets rendered their data — none of these is inside the error state.
    expect(await screen.findByText("Smoothie")).toBeInTheDocument(); // top-products
    expect(screen.getByText("新宿店")).toBeInTheDocument(); // shop-performance
    expect(screen.getByText("Main")).toBeInTheDocument(); // category-sales
    expect(screen.getByText("ORD-001")).toBeInTheDocument(); // recent-orders

    // Exactly one widget is in the error state, not the whole page.
    expect(screen.getAllByText(ERROR_TEXT)).toHaveLength(1);
    // A retry affordance is offered for the failed widget.
    expect(screen.getByRole("button", { name: RETRY_TEXT })).toBeInTheDocument();
  });
});

describe("TC-DASH-113 — empty-state for a brand with no data", () => {
  it("renders zeros and 'no data' placeholders, never NaN/undefined", async () => {
    mockApiEmpty();

    const Wrapper = createWrapper();
    const { container } = render(
      <Wrapper>
        <DashboardPage />
      </Wrapper>
    );

    // List widgets (category, shop-performance, top-products, recent-orders) show
    // the "no data" placeholder once their empty arrays resolve.
    const NO_DATA = "データがありません";
    await waitFor(
      () => {
        expect(screen.getAllByText(NO_DATA).length).toBeGreaterThan(0);
      },
      { timeout: 3000 }
    );

    // KPI cards render literal "0" values, not undefined/NaN.
    // orders_value → "0件"; revenue/products formatted to a 0 amount.
    expect(screen.getByText("0件")).toBeInTheDocument();

    // Hard guarantee: the rendered DOM contains neither "NaN" nor "undefined".
    expect(container.textContent).not.toMatch(/NaN/);
    expect(container.textContent).not.toMatch(/undefined/);

    // No widget fell into the error state — empty data is a valid success.
    expect(screen.queryByText("読み込みに失敗しました")).toBeNull();
  });
});
