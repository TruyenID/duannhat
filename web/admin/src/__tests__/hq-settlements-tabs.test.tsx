/**
 * #1157 — HQ settlement reconciliation screens (plan-050 M5 T5.1).
 *
 * Two failures are pinned here because both are silent, and both land on an
 * accountant rather than on a developer:
 *
 * 1. **A money amount rendered in the wrong scale.** The API speaks `*_minor`
 *    integers. `minor / 100` is right for USD and wrong by two orders of
 *    magnitude for JPY and VND — the only two currencies this platform bills
 *    in. ¥12.34 where ¥1,234 belongs still looks like money, so nothing
 *    downstream catches it. `lib/money-minor.test.ts` pins the formatter; this
 *    file pins that the TABLE actually reaches for the row's own currency
 *    rather than the UI locale's.
 *
 * 2. **"No rows" rendered the same as "could not load".** On a reconciliation
 *    screen those mean opposite things: an empty payout list says the gateway
 *    paid nothing out, a failed request says we do not know what it paid out.
 *    A blank table for both invites someone to close the month on the second
 *    one believing it was the first.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

vi.mock("next/navigation", () => ({
  useParams: () => ({ brandSlug: "test-brand" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/hq/test-brand/settings/payments/settlements",
}));

/** Set per test: what `apiFetch` should do for the settlement endpoints. */
let respond: (url: string) => unknown = () => ({
  data: [],
  meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 },
});

vi.mock("@/lib/api", () => {
  class ApiError extends Error {
    status: number;
    body: Record<string, unknown>;
    constructor(status: number, body: Record<string, unknown>) {
      super((body.message as string) || `API Error ${status}`);
      this.status = status;
      this.body = body;
      this.name = "ApiError";
    }
  }

  return {
    ApiError,
    apiFetch: vi.fn().mockImplementation((url: string) => {
      try {
        return Promise.resolve(respond(url));
      } catch (error) {
        return Promise.reject(error);
      }
    }),
  };
});

import { ApiError } from "@/lib/api";
import { AppProvider } from "@/providers/app-provider";
import { PAYMENTS_STATE_TEST_IDS } from "@/app/shop/[shopSlug]/settings/payments/lib/payments-view-state";
import { BatchesTab } from "@/app/hq/[brandSlug]/settings/payments/settlements/components/batches-tab";
import { PayoutsTab } from "@/app/hq/[brandSlug]/settings/payments/settlements/components/payouts-tab";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      {/* `en` so the assertions below can be written in English. */}
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

const TAB_PROPS = {
  brandSlug: "test-brand",
  connections: [],
  connectionId: "all",
  status: "all",
  page: 1,
  perPage: 25,
  setFilter: vi.fn(),
  setPage: vi.fn(),
};

function payoutPage(rows: Array<Record<string, unknown>>) {
  return {
    data: rows,
    meta: { current_page: 1, per_page: 25, total: rows.length, last_page: 1 },
  };
}

/** Every rendered money figure on screen, in DOM order. */
function renderedAmounts(): string[] {
  return Array.from(document.querySelectorAll('[data-slot="minor-amount"]')).map(
    (node) => node.textContent ?? ""
  );
}

/** Read a formatted amount back as a number (ja-JP grouping: "," / "."). */
function amountOf(text: string): number {
  return Number(text.replace(/[^\d\-.,]/g, "").replace(/,/g, ""));
}

beforeEach(() => {
  vi.clearAllMocks();
});

describe("Settlement money display follows the row's currency (#1157)", () => {
  it("does not divide a JPY payout by 100", async () => {
    respond = () =>
      payoutPage([
        {
          id: "po-jpy",
          connection_id: "conn-1",
          provider: "stripe",
          external_payout_id: "po_abc",
          gross_minor: 1234,
          fee_minor: 34,
          net_minor: 1200,
          currency: "JPY",
          status: "paid",
          expected_arrival_date: "2026-08-01",
          paid_at: null,
          reconciled_at: null,
          bank_ref: null,
        },
      ]);

    render(<PayoutsTab {...TAB_PROPS} />, { wrapper });

    await waitFor(() => expect(renderedAmounts().length).toBeGreaterThan(0));

    const amounts = renderedAmounts();
    expect(amounts.map(amountOf)).toEqual([1234, 34, 1200]);
    // The exact shape of the bug: yen shown as if it had two decimal places.
    expect(amounts.join(" ")).not.toContain("12.34");
  });

  it("does not divide a VND payout by 100 either", async () => {
    respond = () =>
      payoutPage([
        {
          id: "po-vnd",
          connection_id: "conn-2",
          provider: "paypay",
          external_payout_id: "po_vnd",
          gross_minor: 1234567,
          fee_minor: 0,
          net_minor: 1234567,
          currency: "VND",
          status: "paid",
          expected_arrival_date: null,
          paid_at: null,
          reconciled_at: null,
          bank_ref: null,
        },
      ]);

    render(<PayoutsTab {...TAB_PROPS} />, { wrapper });

    await waitFor(() => expect(renderedAmounts().length).toBeGreaterThan(0));
    expect(renderedAmounts().map(amountOf)).toEqual([1234567, 0, 1234567]);
  });

  it("scales a USD payout, in the same table, on the same page", async () => {
    // Two currencies side by side is the case a single hardcoded divisor
    // cannot get right: whichever constant is chosen, one of these rows is off.
    respond = () =>
      payoutPage([
        {
          id: "po-jpy",
          connection_id: "conn-1",
          provider: "stripe",
          external_payout_id: "po_jpy",
          gross_minor: 5000,
          fee_minor: 0,
          net_minor: 5000,
          currency: "JPY",
          status: "paid",
          expected_arrival_date: null,
          paid_at: null,
          reconciled_at: null,
          bank_ref: null,
        },
        {
          id: "po-usd",
          connection_id: "conn-1",
          provider: "stripe",
          external_payout_id: "po_usd",
          gross_minor: 5000,
          fee_minor: 0,
          net_minor: 5000,
          currency: "USD",
          status: "paid",
          expected_arrival_date: null,
          paid_at: null,
          reconciled_at: null,
          bank_ref: null,
        },
      ]);

    render(<PayoutsTab {...TAB_PROPS} />, { wrapper });

    await waitFor(() => expect(renderedAmounts().length).toBe(6));
    const amounts = renderedAmounts().map(amountOf);
    expect(amounts.slice(0, 3)).toEqual([5000, 0, 5000]); // JPY row
    expect(amounts.slice(3)).toEqual([50, 0, 50]); // USD row — 5000 cents
  });

  it("shows raw minor units rather than guessing at an unknown currency", async () => {
    respond = () =>
      payoutPage([
        {
          id: "po-x",
          connection_id: "conn-9",
          provider: "internal",
          external_payout_id: null,
          gross_minor: 1234,
          fee_minor: 0,
          net_minor: 1234,
          currency: "",
          status: "pending",
          expected_arrival_date: null,
          paid_at: null,
          reconciled_at: null,
          bank_ref: null,
        },
      ]);

    render(<PayoutsTab {...TAB_PROPS} />, { wrapper });

    await waitFor(() => expect(renderedAmounts().length).toBeGreaterThan(0));
    const amounts = renderedAmounts();
    expect(amounts[0]).toContain("minor units");
    expect(amountOf(amounts[0])).toBe(1234);
  });
});

describe("An empty settlement table is not an error (#1157)", () => {
  it("renders the empty state — muted message, no error panel, no retry", async () => {
    respond = () => ({
      data: [],
      meta: { current_page: 1, per_page: 25, total: 0, last_page: 1 },
    });

    render(<BatchesTab {...TAB_PROPS} />, { wrapper });

    await waitFor(() =>
      expect(screen.getByTestId(PAYMENTS_STATE_TEST_IDS.empty)).toBeInTheDocument()
    );

    expect(screen.getByText("No batches have been imported.")).toBeInTheDocument();
    expect(screen.queryByTestId(PAYMENTS_STATE_TEST_IDS.error)).toBeNull();
    expect(screen.queryByText("Could not load the data")).toBeNull();
    expect(screen.queryByRole("button", { name: "Retry" })).toBeNull();
  });

  it("renders the error state — alert plus retry, and NOT the empty message", async () => {
    respond = () => {
      throw new ApiError(500, { message: "boom" });
    };

    render(<BatchesTab {...TAB_PROPS} />, { wrapper });

    await waitFor(() =>
      expect(screen.getByTestId(PAYMENTS_STATE_TEST_IDS.error)).toBeInTheDocument()
    );

    expect(screen.getByText("Could not load the data")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Retry" })).toBeInTheDocument();
    // The load failed, so the "there is nothing here" wording must be absent —
    // that is the sentence that would be read as a clean month.
    expect(screen.queryByText("No batches have been imported.")).toBeNull();
    expect(screen.queryByTestId(PAYMENTS_STATE_TEST_IDS.empty)).toBeNull();
  });

  it("says a 403 is a permission problem, not an empty ledger", async () => {
    respond = () => {
      throw new ApiError(403, { message: "forbidden" });
    };

    render(<BatchesTab {...TAB_PROPS} />, { wrapper });

    await waitFor(() =>
      expect(screen.getByTestId(PAYMENTS_STATE_TEST_IDS.forbidden)).toBeInTheDocument()
    );

    expect(
      screen.getByText("You do not have permission to view this brand's gateway connections.")
    ).toBeInTheDocument();
    expect(screen.queryByText("No batches have been imported.")).toBeNull();
  });
});
