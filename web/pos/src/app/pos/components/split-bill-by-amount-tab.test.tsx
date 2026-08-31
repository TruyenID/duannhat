import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { SplitBillByAmountTab } from "./split-bill-by-amount-tab";
import type { CustomerOrder, PaymentMethod } from "../types";

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

function makeOrder(over: Partial<CustomerOrder> = {}): CustomerOrder {
  return {
    id: "ord-1",
    total_amount: 300_000,
    // Remaining is intentionally BELOW total: the order was partially paid
    // (100k) before the tab opened, so the outstanding is 200k.
    remaining_amount: "200000",
    paid_amount: 100_000,
    ...over,
  } as unknown as CustomerOrder;
}

const methods: PaymentMethod[] = [
  {
    id: "m-cash",
    code: "cash",
    name: "Cash",
    is_auto_confirm: true,
    requires_tendered: false,
    is_active: true,
    sort_order: 0,
    branch_id: null,
    organization_id: "org-1",
    translations: {},
    created_at: null,
    updated_at: null,
    deleted_at: null,
  },
];

function tabUi(order: CustomerOrder) {
  return (
    <Wrapper>
      <SplitBillByAmountTab
        order={order}
        methods={methods}
        methodsLoading={false}
        onCreatePayment={vi.fn(() => Promise.resolve({ id: "pay-1" }))}
        onClose={vi.fn()}
      />
    </Wrapper>
  );
}

// Wrap manually (not the {wrapper} option) so rerender re-renders the SAME
// tree — passing a wrapped ui to rerender under {wrapper} would double-wrap and
// remount the tab, resetting its row state.
function renderTab(order: CustomerOrder) {
  return render(tabUi(order));
}

describe("SplitBillByAmountTab — balance against the outstanding, snapshotted", () => {
  // Fixture: total 300k, already paid 100k → outstanding 200k. The tab must
  // collect the OUTSTANDING, not the full total. Balancing against the total
  // was the overpayment bug: every "Thu" 422'd with `overpayment_blocked`
  // because the amount exceeded the 200k the backend still owes.

  it("balances (✓) when rows sum to the OUTSTANDING, not the full total", () => {
    renderTab(makeOrder());

    // Two default rows → allocate the OUTSTANDING (200k = 100k + 100k).
    const amounts = screen.getAllByRole("spinbutton");
    expect(amounts).toHaveLength(2);
    fireEvent.change(amounts[0], { target: { value: "100000" } });
    fireEvent.change(amounts[1], { target: { value: "100000" } });

    expect(screen.getByText("✓")).toBeInTheDocument();
    expect(screen.queryByText("✗")).not.toBeInTheDocument();
  });

  it("over-collecting to the full total on a partially-paid order is unbalanced (✗)", () => {
    renderTab(makeOrder());

    // Σ rows = 300k = full total, but only 200k is outstanding → drift → ✗.
    // Under the old bug this read balanced and each Thu overpaid → 422.
    const amounts = screen.getAllByRole("spinbutton");
    fireEvent.change(amounts[0], { target: { value: "150000" } });
    fireEvent.change(amounts[1], { target: { value: "150000" } });

    expect(screen.getByText("✗")).toBeInTheDocument();
    expect(screen.queryByText("✓")).not.toBeInTheDocument();
  });

  it("snapshots the outstanding at open — a later remaining_amount refetch does NOT drift it (#536)", () => {
    const { rerender } = renderTab(makeOrder());

    const amounts = screen.getAllByRole("spinbutton");
    fireEvent.change(amounts[0], { target: { value: "100000" } });
    fireEvent.change(amounts[1], { target: { value: "100000" } });
    expect(screen.getByText("✓")).toBeInTheDocument();

    // Simulate the parent refetching mid-split (same order id) with a shrunk
    // remaining after a row lands. The snapshot (200k) must hold so the footer
    // stays ✓ — reading live remaining here was the #536 jam.
    rerender(tabUi(makeOrder({ remaining_amount: "100000" })));

    expect(screen.getByText("✓")).toBeInTheDocument();
    expect(screen.queryByText("✗")).not.toBeInTheDocument();
  });
});

describe("SplitBillByAmountTab — tendered amount for cash", () => {
  it("sends tendered_amount == amount for a requires_tendered method", async () => {
    // Cash needs a tendered amount ≥ the charge; each person pays their exact
    // share (no change). Omitting it 422'd "Tendered amount must be provided…".
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    const cashRequiresTender: PaymentMethod = {
      ...methods[0],
      requires_tendered: true,
    };
    render(
      <Wrapper>
        <SplitBillByAmountTab
          order={makeOrder()}
          methods={[cashRequiresTender]}
          methodsLoading={false}
          onCreatePayment={onCreatePayment}
          onClose={vi.fn()}
        />
      </Wrapper>,
    );

    // Allocate the outstanding (200k = 100k + 100k) so "Thu" enables.
    const amounts = screen.getAllByRole("spinbutton");
    fireEvent.change(amounts[0], { target: { value: "100000" } });
    fireEvent.change(amounts[1], { target: { value: "100000" } });

    // Pick the cash method on row 0, then collect. Test locale is DEFAULT_LOCALE
    // = "ja", so the collect button reads "受取".
    fireEvent.click(screen.getAllByRole("button", { name: /cash/i })[0]);
    fireEvent.click(screen.getAllByRole("button", { name: "受取" })[0]);

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    expect(onCreatePayment).toHaveBeenCalledWith(
      expect.objectContaining({ amount: 100_000, tendered_amount: 100_000 }),
    );
  });

  it("sends a 0-based bill_index (first person = 0, not 1)", async () => {
    // metadata bill_index is 0-based (workstation prints slipIndex = it + 1).
    // Sending the 1-based display index made the slip read "2/2", "3/2".
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    render(
      <Wrapper>
        <SplitBillByAmountTab
          order={makeOrder()}
          methods={methods}
          methodsLoading={false}
          onCreatePayment={onCreatePayment}
          onClose={vi.fn()}
        />
      </Wrapper>,
    );
    const amounts = screen.getAllByRole("spinbutton");
    fireEvent.change(amounts[0], { target: { value: "100000" } });
    fireEvent.change(amounts[1], { target: { value: "100000" } });
    fireEvent.click(screen.getAllByRole("button", { name: /cash/i })[0]);
    fireEvent.click(screen.getAllByRole("button", { name: "受取" })[0]);

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    expect(onCreatePayment.mock.calls[0]![0].metadata).toMatchObject({
      split_mode: "by_amount",
      bill_index: 0, // first person, 0-based
      total_bills: 2,
    });
  });
});

describe("SplitBillByAmountTab — distinct per-guest payment ids", () => {
  it("each guest carries its OWN payment id (selecting one ≠ selecting all)", async () => {
    // Distinct id per created payment — the real backend behaviour.
    let n = 0;
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: `pay-${++n}` }));
    const onAllRowsPaid = vi.fn();
    render(
      <Wrapper>
        <SplitBillByAmountTab
          order={makeOrder()}
          methods={methods}
          methodsLoading={false}
          onCreatePayment={onCreatePayment}
          onAllRowsPaid={onAllRowsPaid}
          onClose={vi.fn()}
        />
      </Wrapper>,
    );

    // Allocate the outstanding (200k = 100k + 100k) + pick the method on both.
    const amounts = screen.getAllByRole("spinbutton");
    fireEvent.change(amounts[0], { target: { value: "100000" } });
    fireEvent.change(amounts[1], { target: { value: "100000" } });
    const cashChips = screen.getAllByRole("button", { name: /cash/i });
    fireEvent.click(cashChips[0]);
    fireEvent.click(cashChips[1]);

    // Pay row 0, then row 1 (its "受取" button is the only one left once row 0
    // flips to paid). The last payment fires onAllRowsPaid.
    fireEvent.click(screen.getAllByRole("button", { name: "受取" })[0]);
    await waitFor(() =>
      expect(screen.getAllByRole("button", { name: "受取" })).toHaveLength(1),
    );
    fireEvent.click(screen.getAllByRole("button", { name: "受取" })[0]);

    await waitFor(() => expect(onAllRowsPaid).toHaveBeenCalledTimes(1));
    const session = onAllRowsPaid.mock.calls[0]![0];
    const ids = session.guests.map((g: { paymentId: string }) => g.paymentId);
    expect(ids).toEqual(["pay-1", "pay-2"]);
    // The critical invariant: no two guests share an id.
    expect(new Set(ids).size).toBe(ids.length);
  });
});
