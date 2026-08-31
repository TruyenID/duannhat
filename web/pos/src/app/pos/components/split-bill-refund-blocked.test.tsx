/**
 * #2668 — refund refused because the workstation shift is still open.
 *
 * `Shop/OrderPaymentController::refund` answers 409 with the frozen code
 * `REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT`. The cashier reaches it WITHOUT ever
 * having chosen Cloud: `apiFetch`'s auto-mode net retries a LAN network error
 * once against Cloud, so from the till's seat they pressed "Refund" on the
 * shop's own POS and were refused by a server they never picked.
 *
 * What is pinned here is what the CASHIER READS, not the error object:
 *   1. the 409 with that code renders copy that names the workstation and says
 *      the refund has to happen on the shop POS,
 *   2. the raw backend envelope message does NOT reach the row,
 *   3. a DIFFERENT 409 code still shows the server's own message — the branch
 *      keys off the code, not off the status.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import { SplitBillEvenTab } from "./split-bill-even-tab";
import type { CustomerOrder, CustomerOrderItem, PaymentMethod } from "../types";

beforeAll(() => {
  const proto = window.HTMLElement.prototype as unknown as Record<string, unknown>;
  proto.scrollIntoView = vi.fn();
  proto.hasPointerCapture = vi.fn(() => false);
  proto.setPointerCapture = vi.fn();
  proto.releasePointerCapture = vi.fn();
});

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("pos_locale", "en");
});

const CARD: PaymentMethod = {
  id: "m-card",
  code: "card",
  name: "Card",
  is_auto_confirm: true,
  requires_tendered: false,
  is_active: true,
  sort_order: 0,
  branch_id: null,
  organization_id: "org-1",
  translations: {},
} as unknown as PaymentMethod;

function makeOrder(): CustomerOrder {
  const item = {
    id: "item-1",
    customer_order_id: "order-1",
    product_sku_id: "sku-1",
    quantity: 1,
    unit_price: 200_000,
    topping_subtotal: 0,
    subtotal: 200_000,
    status: "pending",
    note: null,
    product_sku: {
      id: "sku-1",
      name: "Món 1",
      product: { id: "p-1", name: "Món 1" },
    },
  } as unknown as CustomerOrderItem;

  return {
    id: "order-1",
    order_code: "ORD-T-0001",
    order_type: "dine_in",
    status: "checkout",
    subtotal: 200_000,
    discount_amount: 0,
    service_charge: 0,
    tax_amount: 0,
    total_amount: 200_000,
    paid_amount: 0,
    remaining_amount: "200000",
    guest_count: 2,
    items: [item],
    customer: null,
    customer_id: null,
    tables: [],
  } as unknown as CustomerOrder;
}

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

/** Radix Select opens on keyboard Enter under jsdom (pointerdown is a no-op). */
function pickMethodFromSelect(scope: HTMLElement, name: string) {
  const trigger = within(scope).getByRole("combobox");
  trigger.focus();
  fireEvent.keyDown(trigger, { key: "Enter" });
  fireEvent.click(screen.getByRole("option", { name: new RegExp(name) }));
}

function renderEqual(onRefundPayment: ReturnType<typeof vi.fn>) {
  render(
    <SplitBillEvenTab
      open
      onOpenChange={vi.fn()}
      order={makeOrder()}
      methods={[CARD]}
      methodsLoading={false}
      splitData={{ per_person_amounts: [100_000, 100_000] } as never}
      splitLoading={false}
      splitError={null}
      splitCount={2}
      onChangeSplitCount={vi.fn()}
      onCreatePayment={vi.fn(() => Promise.resolve({ id: "pay-1" })) as never}
      onRefundPayment={onRefundPayment as never}
      onCancelSplit={vi.fn()}
      onAllRowsPaid={vi.fn()}
    />,
    { wrapper: Wrapper },
  );
}

/** Collect guest 1's share so the row grows a Refund button, then press it. */
async function collectThenRefundFirstRow() {
  const rows = screen.getAllByRole("listitem");
  pickMethodFromSelect(rows[0]!, "Card");
  fireEvent.click(within(screen.getAllByRole("listitem")[0]!).getByRole("button", {
    name: "Collect",
  }));

  const refund = await screen.findByRole("button", { name: "Refund" });
  fireEvent.click(refund);
}

describe("refund refused by an open workstation shift", () => {
  it("tells the cashier to refund on the shop's workstation POS", async () => {
    const onRefundPayment = vi.fn(() =>
      Promise.reject(
        new ApiError(409, {
          message: "Refund is blocked while the workstation shift is open.",
          code: "REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT",
          payment_id: "pay-1",
          till_session_id: "till-session-1",
        }),
      ),
    );
    renderEqual(onRefundPayment);
    await collectThenRefundFirstRow();

    await waitFor(() => expect(onRefundPayment).toHaveBeenCalledTimes(1));

    // What the cashier reads: the workstation is named, and so is the action.
    const shown = await screen.findByText(/workstation POS/);
    expect(shown.textContent).toContain("a cashier shift is still open there");
    expect(shown.textContent).toContain("Try again on the shop POS");

    // The bare server sentence must not be what they see instead.
    expect(
      screen.queryByText(/Refund is blocked while the workstation shift is open\./),
    ).toBeNull();
  });

  it("keeps the row settled so the money is not shown as re-collectable", async () => {
    const onRefundPayment = vi.fn(() =>
      Promise.reject(
        new ApiError(409, {
          message: "blocked",
          code: "REFUND_BLOCKED_WORKSTATION_OPEN_SHIFT",
        }),
      ),
    );
    renderEqual(onRefundPayment);
    await collectThenRefundFirstRow();

    // Refusal ≠ refunded: the row stays "succeeded", so the Refund button is
    // still the only next step and no one re-collects an already-paid share.
    await screen.findByText(/workstation POS/);
    expect(screen.getByRole("button", { name: "Refund" })).toBeInTheDocument();
  });

  it("still shows the server's own message for a DIFFERENT 409 code", async () => {
    const onRefundPayment = vi.fn(() =>
      Promise.reject(
        new ApiError(409, {
          message: "Payment already fully refunded.",
          code: "PAYMENT_ALREADY_REFUNDED",
        }),
      ),
    );
    renderEqual(onRefundPayment);
    await collectThenRefundFirstRow();

    await screen.findByText("Payment already fully refunded.");
    expect(screen.queryByText(/workstation POS/)).toBeNull();
  });
});
