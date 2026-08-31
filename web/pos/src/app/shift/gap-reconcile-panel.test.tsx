import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { GapPreview } from "@/services/till-service";
import type { GapClaimState } from "./gap-claim";

// ── Mock the service so useGapPreview never touches the network ───────────────
const { gapPreview } = vi.hoisted(() => ({
  gapPreview: vi.fn<() => Promise<{ data: GapPreview }>>(),
}));

vi.mock("@/services/till-service", () => ({
  tillService: { gapPreview },
}));

import { GapReconcilePanel } from "./gap-reconcile-panel";

// Pass-through translator so assertions can match on i18n keys.
const t = (k: string, p?: Record<string, string>) =>
  p?.count ? `${k}:${p.count}` : k;

function wrap(node: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{node}</QueryClientProvider>,
  );
}

function preview(overrides?: Partial<GapPreview>): GapPreview {
  return {
    previous_session: {
      id: "prev",
      session_code: "S001",
      ended_at: "2026-07-15T11:00:00Z",
    },
    gap_window: { from: "2026-07-15T11:00:00Z", to: "2026-07-15T12:00:00Z" },
    currency_code: "JPY",
    payments: [
      {
        id: "pay-cash",
        order_id: "o1",
        order_code: "ORD-1",
        amount: 800,
        method_code: "cash",
        is_cash: true,
        created_at: "2026-07-15T11:30:00Z",
      },
      {
        id: "pay-card",
        order_id: "o2",
        order_code: "ORD-2",
        amount: 500,
        method_code: "card",
        method_label: "Card",
        is_cash: false,
        created_at: "2026-07-15T11:40:00Z",
      },
    ],
    totals: { count: 2, cash_amount: 800, non_cash_amount: 500 },
    ...overrides,
  };
}

beforeEach(() => {
  gapPreview.mockReset();
});

describe("GapReconcilePanel — plan-044 R2", () => {
  it("renders gap payments with a cash held-separately badge", async () => {
    gapPreview.mockResolvedValue({ data: preview() });
    wrap(
      <GapReconcilePanel
        shopSlug="shop-1"
        fallbackCurrency="JPY"
        onChange={() => {}}
        t={t}
      />,
    );

    expect(await screen.findByText("ORD-1")).toBeInTheDocument();
    expect(screen.getByText("ORD-2")).toBeInTheDocument();
    // Exactly one cash row → one held-separately badge.
    expect(
      screen.getAllByText("shift.open.gap_reconcile.cash_held_badge"),
    ).toHaveLength(1);
  });

  it("requires the held-separately ack once a cash row is ticked, and reports it up", async () => {
    gapPreview.mockResolvedValue({ data: preview() });
    const onChange = vi.fn<(s: GapClaimState) => void>();
    wrap(
      <GapReconcilePanel
        shopSlug="shop-1"
        fallbackCurrency="JPY"
        onChange={onChange}
        t={t}
      />,
    );

    await screen.findByText("ORD-1");

    // No cash callout before anything is selected.
    expect(
      screen.queryByText("shift.open.gap_reconcile.cash_callout_title"),
    ).not.toBeInTheDocument();

    // Tick the cash row (first checkbox in DOM order).
    const rowChecks = screen.getAllByRole("checkbox");
    fireEvent.click(rowChecks[0]);

    // Callout + ack checkbox appear; onChange reports cashSelected but ack=false.
    expect(
      await screen.findByText("shift.open.gap_reconcile.cash_callout_title"),
    ).toBeInTheDocument();
    await waitFor(() => {
      const last = onChange.mock.calls.at(-1)?.[0];
      expect(last?.claimedIds).toEqual(["pay-cash"]);
      expect(last?.cashSelected).toBe(true);
      expect(last?.ack).toBe(false);
    });

    // Tick the ack → onChange reports ack=true.
    fireEvent.click(
      screen.getByLabelText("shift.open.gap_reconcile.cash_ack_label"),
    );
    await waitFor(() => {
      expect(onChange.mock.calls.at(-1)?.[0]?.ack).toBe(true);
    });
  });

  it("ticking only a non-cash row needs no ack (cashSelected false)", async () => {
    gapPreview.mockResolvedValue({ data: preview() });
    const onChange = vi.fn<(s: GapClaimState) => void>();
    wrap(
      <GapReconcilePanel
        shopSlug="shop-1"
        fallbackCurrency="JPY"
        onChange={onChange}
        t={t}
      />,
    );
    await screen.findByText("ORD-2");

    // Second checkbox = the card (non-cash) row.
    fireEvent.click(screen.getAllByRole("checkbox")[1]);

    await waitFor(() => {
      const last = onChange.mock.calls.at(-1)?.[0];
      expect(last?.claimedIds).toEqual(["pay-card"]);
      expect(last?.cashSelected).toBe(false);
    });
    // No cash callout.
    expect(
      screen.queryByText("shift.open.gap_reconcile.cash_callout_title"),
    ).not.toBeInTheDocument();
  });

  it("renders nothing when there is no prior terminal session", async () => {
    gapPreview.mockResolvedValue({
      data: preview({ previous_session: null, payments: [] }),
    });
    const { container } = wrap(
      <GapReconcilePanel
        shopSlug="shop-1"
        fallbackCurrency="JPY"
        onChange={() => {}}
        t={t}
      />,
    );
    // Give the query a tick to resolve, then assert the panel stayed empty.
    await waitFor(() => expect(gapPreview).toHaveBeenCalled());
    expect(
      container.querySelector('[class*="border-amber"]'),
    ).not.toBeInTheDocument();
  });

  it("stays non-blocking when the preview fetch errors", async () => {
    gapPreview.mockRejectedValue(new Error("LAN unreachable"));
    const { container } = wrap(
      <GapReconcilePanel
        shopSlug="shop-1"
        fallbackCurrency="JPY"
        onChange={() => {}}
        t={t}
      />,
    );
    await waitFor(() => expect(gapPreview).toHaveBeenCalled());
    // No panel rendered — the cashier can still open the shift.
    expect(
      screen.queryByText("shift.open.gap_reconcile.section"),
    ).not.toBeInTheDocument();
  });
});
