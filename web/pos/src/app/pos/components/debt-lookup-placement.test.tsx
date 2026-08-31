/**
 * "Tra cứu nợ" — where the trigger lives, and what the dialog can actually do.
 *
 * The feature shipped reachable only from inside OrderCart, which early-returns
 * without an order: answering a SHOP-WIDE question began with creating an order
 * for a customer who might not even be the debtor. And picking a debtor fired a
 * toast and nothing else — there was no settlement path at all.
 *
 * These pin both halves.
 */

import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { MemoryRouter } from "react-router-dom";

const debtServiceMock = vi.hoisted(() => ({
  list: vi.fn(),
  listForCustomer: vi.fn(),
  listPartPaid: vi.fn(),
}));
vi.mock("@/services/debt-service", () => ({ debtService: debtServiceMock }));

vi.mock("@/providers/app-provider", () => ({
  useTranslation: () => ({ t: (k: string) => k, locale: "vi" }),
  // Same shape as useTranslation — the help `?` inside the dialog reads it.
  useOptionalTranslation: () => ({ t: (k: string) => k, locale: "vi" }),
  useLocale: () => ({ locale: "vi", setLocale: vi.fn(), locales: {}, t: (k: string) => k }),
}));

import { DebtSearchDialog } from "./debt-search-dialog";
import type { EffectivePaymentOption } from "../types";

const CUSTOMER = {
  customer_id: "cus-1",
  customer_name: "Nguyen Van A",
  customer_phone: "0900000199",
  customer_tax_code: null,
  open_debt_count: 2,
  open_debt_total: "155000",
  oldest_debt_at: "2026-08-06 10:00:00",
  latest_debt_at: "2026-08-06 11:00:00",
};

const DEBT = {
  payment_id: "pay-1",
  order_id: "ord-debt",
  order_code: "WS-0001",
  amount: "120000",
  net_amount: "120000",
  is_settleable: true,
  created_at: "2026-08-06 10:00:00",
  note: null,
};

const CASH: EffectivePaymentOption = {
  id: "opt-cash",
  display_name: "Tiền mặt",
  provider: "internal",
  rail: "cash",
  method_type: "cash",
  effective: true,
  source: "internal_catalog",
  reason: "internal_tender",
  error_code: null,
  connection_id: null,
  connection_option_id: null,
  shop_option_id: null,
  shop_preference: "inherit",
  device_preference: "inherit",
  legacy_payment_method_id: "pm-cash",
  legacy_payment_method_code: "cash",
  client: {
    requires_tendered: true,
    immediate_settlement: true,
    supports_pos_checkout: true,
  },
};

function Wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return (
    <MemoryRouter initialEntries={["/shop/ningyocho"]}>
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    </MemoryRouter>
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  debtServiceMock.list.mockResolvedValue({ data: [CUSTOMER], next_cursor: null });
  debtServiceMock.listForCustomer.mockResolvedValue({ data: [DEBT] });
  debtServiceMock.listPartPaid.mockResolvedValue({ data: [] });
});

async function openCustomer() {
  await screen.findByText("Nguyen Van A");
  fireEvent.click(screen.getByText("Nguyen Van A"));
  await screen.findByText("WS-0001");
}

describe("DebtSearchDialog — reachable with no order, and it drills down", () => {
  it("lists debtors without any order in context", async () => {
    render(
      <DebtSearchDialog open shopSlug="ningyocho" onOpenChange={vi.fn()} activeOrder={null} />,
      { wrapper: Wrapper },
    );

    // No `activeOrder` at all: this is the state the old placement made
    // unreachable, because OrderCart does not render without an order.
    expect(await screen.findByText("Nguyen Van A")).toBeInTheDocument();
    expect(debtServiceMock.list).toHaveBeenCalled();
  });

  it("drills into one customer's INDIVIDUAL debts", async () => {
    render(
      <DebtSearchDialog open shopSlug="ningyocho" onOpenChange={vi.fn()} activeOrder={null} />,
      { wrapper: Wrapper },
    );
    await openCustomer();

    // The aggregate can never be settled — `settles_payment_id` exists only on
    // the individual rows. Drilling down is what makes collection possible.
    expect(debtServiceMock.listForCustomer).toHaveBeenCalledWith("cus-1");
    expect(screen.getByText("WS-0001")).toBeInTheDocument();
  });
});

describe("DebtSearchDialog — collecting", () => {
  it("settles against the ACTIVE order, not the debt's own closed order", async () => {
    const onSettle = vi.fn().mockResolvedValue(undefined);
    render(
      <DebtSearchDialog
        open
        shopSlug="ningyocho"
        onOpenChange={vi.fn()}
        activeOrder={{ id: "ord-live", customerId: "cus-1" }}
        paymentOptions={[CASH]}
        onSettle={onSettle}
      />,
      { wrapper: Wrapper },
    );
    await openCustomer();

    fireEvent.click(screen.getByRole("button", { name: "pos.debt.settle" }));

    await waitFor(() => expect(onSettle).toHaveBeenCalledTimes(1));
    const req = onSettle.mock.calls[0][0];
    // `ord-debt` is the order the debt sits on and it is CLOSED — the backend
    // refuses any payment on it. The settlement must ride the live order.
    expect(req.orderId).toBe("ord-live");
    expect(req.debt.payment_id).toBe("pay-1");
    // The ORIGINAL amount, never the net: the backend compares against
    // `orig->amount` and rejects `settles_amount_mismatch` otherwise.
    expect(req.tenderedAmount).toBe(120000);
  });

  it("refuses to offer collection when the active order is a DIFFERENT customer", async () => {
    render(
      <DebtSearchDialog
        open
        shopSlug="ningyocho"
        onOpenChange={vi.fn()}
        activeOrder={{ id: "ord-live", customerId: "someone-else" }}
        paymentOptions={[CASH]}
        onSettle={vi.fn()}
      />,
      { wrapper: Wrapper },
    );
    await openCustomer();

    // Backend rule, not a nicety: `settles_wrong_customer`. Offering the button
    // would mean a 422 with the customer standing at the counter.
    expect(
      screen.getByRole("button", { name: "pos.debt.settle" }),
    ).toBeDisabled();
    expect(screen.getByText("pos.debt.needs_open_order")).toBeInTheDocument();
  });

  it("refuses to offer collection with no order at all", async () => {
    render(
      <DebtSearchDialog
        open
        shopSlug="ningyocho"
        onOpenChange={vi.fn()}
        activeOrder={null}
        paymentOptions={[CASH]}
        onSettle={vi.fn()}
      />,
      { wrapper: Wrapper },
    );
    await openCustomer();

    expect(
      screen.getByRole("button", { name: "pos.debt.settle" }),
    ).toBeDisabled();
  });

  it("blocks a partially refunded debt instead of letting it 422", async () => {
    debtServiceMock.listForCustomer.mockResolvedValue({
      data: [{ ...DEBT, net_amount: "119000", is_settleable: false }],
    });
    render(
      <DebtSearchDialog
        open
        shopSlug="ningyocho"
        onOpenChange={vi.fn()}
        activeOrder={{ id: "ord-live", customerId: "cus-1" }}
        paymentOptions={[CASH]}
        onSettle={vi.fn()}
      />,
      { wrapper: Wrapper },
    );
    await openCustomer();

    // Paying the net trips `settles_amount_mismatch`; paying the original
    // over-collects. Neither is something to discover at the counter.
    expect(
      screen.getByRole("button", { name: "pos.debt.settle" }),
    ).toBeDisabled();
    expect(
      screen.getByText("pos.debt.partially_refunded"),
    ).toBeInTheDocument();
  });
});

const PART_PAID = {
  customer_id: "cus-2",
  customer_name: "Tran Thi B",
  customer_phone: "0965369092",
  customer_tax_code: null,
  order_count: 1,
  total_unpaid: "1255",
  oldest_at: "2026-08-06 19:10:27",
  latest_at: "2026-08-06 19:10:27",
  orders: [
    {
      order_id: "ord-3200",
      order_code: "ORD-2026-3200",
      total_amount: "1265",
      paid_amount: "10",
      unpaid_amount: "1255",
      opened_at: "2026-08-06 19:10:27",
    },
  ],
};

/*
 * Orders left part-paid. Money the shop is owed that no debt report could see:
 * nothing was charged to the account, so the on-account query cannot find it,
 * and it surfaced only in the payment dialog's banner — and only when that same
 * customer happened to be served again.
 *
 * Shown as its OWN section, never folded into the on-account figures: a debt
 * granted on purpose and an order nobody closed are different obligations, and
 * one merged number could not tell them apart.
 */
describe("DebtSearchDialog — part-paid orders", () => {
  it("shows them even when there is no on-account debt at all", async () => {
    debtServiceMock.list.mockResolvedValue({ data: [], next_cursor: null });
    debtServiceMock.listPartPaid.mockResolvedValue({ data: [PART_PAID] });

    render(
      <DebtSearchDialog open shopSlug="ningyocho" onOpenChange={vi.fn()} activeOrder={null} />,
      { wrapper: Wrapper },
    );

    // This is the exact state the shop was in: zero on-account rows, and a real
    // balance the lookup used to answer "No open debts" for.
    expect(await screen.findByText("Tran Thi B")).toBeInTheDocument();
    expect(screen.getByText("pos.debt.section_part_paid")).toBeInTheDocument();
    expect(screen.queryByText("pos.debt.empty")).not.toBeInTheDocument();
  });

  it("keeps the two kinds in separate sections", async () => {
    debtServiceMock.listPartPaid.mockResolvedValue({ data: [PART_PAID] });

    render(
      <DebtSearchDialog open shopSlug="ningyocho" onOpenChange={vi.fn()} activeOrder={null} />,
      { wrapper: Wrapper },
    );

    await screen.findByText("Nguyen Van A");
    expect(screen.getByText("pos.debt.section_on_account")).toBeInTheDocument();
    expect(screen.getByText("pos.debt.section_part_paid")).toBeInTheDocument();
  });

  it("lists the customer's unfinished orders on their detail screen", async () => {
    debtServiceMock.listPartPaid.mockResolvedValue({
      data: [{ ...PART_PAID, customer_id: "cus-1" }],
    });

    render(
      <DebtSearchDialog open shopSlug="ningyocho" onOpenChange={vi.fn()} activeOrder={null} />,
      { wrapper: Wrapper },
    );
    await openCustomer();

    expect(screen.getByText("ORD-2026-3200")).toBeInTheDocument();
    // Read-only: collecting the rest of an order is the ordinary payment flow
    // on that order, not a settlement, so no button is offered here.
    expect(screen.getByText("pos.debt.part_paid_detail_hint")).toBeInTheDocument();
  });

  it("still says empty when BOTH lists are empty", async () => {
    debtServiceMock.list.mockResolvedValue({ data: [], next_cursor: null });
    debtServiceMock.listPartPaid.mockResolvedValue({ data: [] });

    render(
      <DebtSearchDialog open shopSlug="ningyocho" onOpenChange={vi.fn()} activeOrder={null} />,
      { wrapper: Wrapper },
    );

    expect(await screen.findByText("pos.debt.empty")).toBeInTheDocument();
  });
});
