/**
 * #1178 — ManualSettleDialog cash-count grid.
 *
 * The dialog used to filter denominations on `is_active`, a field the API
 * response does not carry. Every row was dropped, the grid rendered empty, and
 * submit posted `closing_counts: []` → 422 → a bare "settle failed" toast with
 * no way for the manager to reconcile an expired shift.
 *
 * These tests pin the contract from the client side: rows render off the real
 * payload shape, an emptied drawer is sent as explicit zero rows, and a grid
 * with nothing in it blocks submit with an explanation instead of posting.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { ManualSettleDialog } from "@/app/shop/[shopSlug]/till/sessions/components/manual-settle-dialog";
import type { StaleSession } from "@/services/till-session-admin-service";

const denominations: { current: Array<Record<string, unknown>> } = { current: [] };
const expectedCash = { current: 58750 };

vi.mock("@/lib/api", () => ({
  ApiError: class ApiError extends Error {},
  apiFetch: vi.fn().mockImplementation((url: string) => {
    if (url.includes("/reconciliation")) {
      return Promise.resolve({
        data: {
          cash: {
            opening_float: 58750,
            cash_sales: 0,
            cash_tips: 0,
            paid_in: 0,
            paid_out: 0,
            loan_from_safe: 0,
            pickup_to_safe: 0,
            expected_cash: expectedCash.current,
          },
        },
      });
    }
    return Promise.resolve({ data: denominations.current });
  }),
}));

// The API response shape as it actually ships — note there is no `is_active`
// in the pre-#1178 backend, which is exactly what broke the grid.
const LEGACY_PAYLOAD = [
  { id: "d10000", currency_code: "JPY", value: 10000, kind: "note", label: null, sort_order: 0 },
  { id: "d1000", currency_code: "JPY", value: 1000, kind: "note", label: null, sort_order: 1 },
];

const SESSION = {
  id: "sess-1",
  session_code: "WS-20260720-034441-8cee06",
  status: "expired",
  business_date: "2026-07-20",
  default_currency_code: "JPY",
  opening_float_amount: 50000,
  expected_cash_amount: 50000,
  counted_cash_amount: null,
  cash_variance_amount: null,
  opened_at: "2026-07-20T03:44:41Z",
  closed_at: null,
  abandoned_at: null,
  expired_at: "2026-07-22T03:44:41Z",
  force_abandoned: false,
  force_abandoned_by_id: null,
  force_abandon_reason_code: null,
  force_abandon_reason_detail: null,
  expire_reason: "no_activity",
  expire_threshold_hours: 48,
  branch_id: "branch-1",
  till_id: "till-1",
  opened_by_id: null,
  closed_by_id: null,
} satisfies StaleSession;

const REASON = "Thu ngân nghỉ việc, quản lý đếm lại két sau 3 ngày.";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

function renderDialog(onConfirm = vi.fn(), varianceTolerance?: number | null) {
  render(
    <ManualSettleDialog
      open
      shopSlug="test-shop"
      session={SESSION}
      varianceTolerance={varianceTolerance}
      onOpenChange={vi.fn()}
      onConfirm={onConfirm}
    />,
    { wrapper }
  );
  return onConfirm;
}

const spinButtons = () => screen.getAllByRole("spinbutton");

/**
 * Read one figure out of the money-summary block by its label. Scoped to the
 * summary because "counted cash" also labels the grid below it.
 */
function summaryAmount(label: RegExp): string {
  const summary = document.querySelector('[data-slot="manual-settle-summary"]');
  const term = Array.from(summary?.querySelectorAll("dt") ?? []).find((dt) =>
    label.test(dt.textContent ?? "")
  );
  return term?.parentElement?.querySelector("dd")?.textContent ?? "";
}

beforeEach(() => {
  denominations.current = LEGACY_PAYLOAD;
  expectedCash.current = 58750;
});

describe("ManualSettleDialog cash-count grid", () => {
  it("renders an input per denomination even when the payload omits is_active", async () => {
    renderDialog();

    await waitFor(() => expect(spinButtons()).toHaveLength(2));
    expect(screen.getByText(/10,000/)).toBeInTheDocument();
  });

  it("still renders rows when is_active is present and true", async () => {
    denominations.current = LEGACY_PAYLOAD.map((d) => ({ ...d, is_active: true }));
    renderDialog();

    await waitFor(() => expect(spinButtons()).toHaveLength(2));
  });

  it("drops a row explicitly flagged inactive", async () => {
    denominations.current = [
      { ...LEGACY_PAYLOAD[0], is_active: true },
      { ...LEGACY_PAYLOAD[1], is_active: false },
    ];
    renderDialog();

    await waitFor(() => expect(spinButtons()).toHaveLength(1));
  });

  it("submits the entered counts", async () => {
    const onConfirm = renderDialog();
    await waitFor(() => expect(spinButtons()).toHaveLength(2));

    fireEvent.change(spinButtons()[0], { target: { value: "5" } });
    fireEvent.change(screen.getByLabelText(/manual-settle reason|reason/i), {
      target: { value: REASON },
    });
    fireEvent.click(screen.getByRole("button", { name: /settle|confirm/i }));

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onConfirm.mock.calls[0][0].closing_counts).toEqual([
      { denomination_id: "d10000", quantity: 5 },
    ]);
  });

  it("sends every denomination at quantity 0 when the drawer is acked empty", async () => {
    const onConfirm = renderDialog();
    await waitFor(() => expect(spinButtons()).toHaveLength(2));

    fireEvent.click(screen.getByRole("checkbox", { name: /drawer empty/i }));
    fireEvent.change(screen.getByLabelText(/reason/i), { target: { value: REASON } });
    fireEvent.click(screen.getByRole("button", { name: /settle|confirm/i }));

    // NOT an empty array — the backend requires a count, and "counted, found
    // nothing" is a different fact from "never counted".
    expect(onConfirm.mock.calls[0][0].closing_counts).toEqual([
      { denomination_id: "d10000", quantity: 0 },
      { denomination_id: "d1000", quantity: 0 },
    ]);
  });

  it("blocks submit with an explanation when there are no denominations to count", async () => {
    denominations.current = [];
    const onConfirm = renderDialog();

    await waitFor(() =>
      expect(screen.getByText(/no denominations available to count/i)).toBeInTheDocument()
    );

    fireEvent.change(screen.getByLabelText(/reason/i), { target: { value: REASON } });
    const submit = screen.getByRole("button", { name: /settle|confirm/i });
    expect(submit).toBeDisabled();

    fireEvent.click(submit);
    expect(onConfirm).not.toHaveBeenCalled();
  });

  it("shows the opening float, and expected cash read from the reconciliation endpoint", async () => {
    // Deliberately different numbers: the opening float comes off the session
    // row, expected cash comes off /reconciliation — `expected_cash_amount` is
    // NULL on an expired session, so the endpoint is the only real source.
    renderDialog();

    await waitFor(() => expect(summaryAmount(/expected cash/i)).toMatch(/58,750/));
    expect(summaryAmount(/opening float/i)).toMatch(/50,000/);
  });

  it("keeps a running counted total and variance while quantities are typed", async () => {
    renderDialog();
    await waitFor(() => expect(spinButtons()).toHaveLength(2));

    fireEvent.change(spinButtons()[0], { target: { value: "5" } });

    // 5 × 10,000 = 50,000 counted → 50,000 − 58,750 expected = −8,750.
    await waitFor(() => expect(summaryAmount(/counted cash/i)).toMatch(/50,000/));
    expect(summaryAmount(/variance/i)).toMatch(/-.*8,750/);
  });

  it("requires a variance reason once the variance passes the till tolerance", async () => {
    const onConfirm = renderDialog(vi.fn(), 1000);
    await waitFor(() => expect(spinButtons()).toHaveLength(2));

    fireEvent.change(spinButtons()[0], { target: { value: "5" } }); // variance −8,750
    fireEvent.change(screen.getByLabelText(/manual-settle reason/i), {
      target: { value: REASON },
    });

    // Backend would answer 422 VARIANCE_REASON_REQUIRED here — block first.
    const submit = screen.getByRole("button", { name: /settle|confirm/i });
    await waitFor(() => expect(submit).toBeDisabled());

    fireEvent.change(screen.getByLabelText(/variance reason/i), {
      target: { value: "Thiếu tiền, đã lập biên bản." },
    });
    expect(submit).toBeEnabled();

    fireEvent.click(submit);
    expect(onConfirm.mock.calls[0][0].closing_note).toBe("Thiếu tiền, đã lập biên bản.");
  });

  it("does not demand a variance reason when the count matches expected", async () => {
    expectedCash.current = 50000;
    renderDialog(vi.fn(), 1000);
    await waitFor(() => expect(spinButtons()).toHaveLength(2));

    fireEvent.change(spinButtons()[0], { target: { value: "5" } }); // variance 0
    fireEvent.change(screen.getByLabelText(/manual-settle reason/i), {
      target: { value: REASON },
    });

    expect(screen.getByRole("button", { name: /settle|confirm/i })).toBeEnabled();
  });

  it("keeps submit disabled until a count is entered", async () => {
    renderDialog();
    await waitFor(() => expect(spinButtons()).toHaveLength(2));

    fireEvent.change(screen.getByLabelText(/reason/i), { target: { value: REASON } });
    expect(screen.getByRole("button", { name: /settle|confirm/i })).toBeDisabled();

    fireEvent.change(spinButtons()[0], { target: { value: "1" } });
    expect(screen.getByRole("button", { name: /settle|confirm/i })).toBeEnabled();
  });
});
