/**
 * Per-guest cash: "khách này đưa bao nhiêu, thối lại bao nhiêu".
 *
 * All three split modes (chia đều · theo số tiền · theo món) used to post
 * `tendered_amount = <that row's own share>`, so every cash row landed in both
 * databases — and on the customer's printed slip — as "đưa đúng, thối 0" no
 * matter what actually crossed the counter. Each mode has its own tab, its own
 * row state and its own submit path, which is exactly why this is asserted per
 * mode rather than once: they have drifted from one another before.
 *
 * What is pinned here, for every mode:
 *   1. the cash box appears only for a `requires_tendered` method,
 *   2. the POSTED `tendered_amount` is what the cashier typed,
 *   3. a short tender cannot be collected (both servers 422 it),
 *   4. the completion snapshot carries tendered + change per guest, so the
 *      receipt screen and the per-guest reprint can show them.
 *
 * Amounts are asserted EXACTLY against the captured request body.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { SplitBillByItemsTab } from "./split-bill-by-items-tab";
import { SplitBillByAmountTab } from "./split-bill-by-amount-tab";
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

// ---------------------------------------------------------------------------
//  Fixtures
// ---------------------------------------------------------------------------

const CASH: PaymentMethod = {
  id: "m-cash",
  code: "cash",
  name: "Cash",
  is_auto_confirm: true,
  requires_tendered: true,
  is_active: true,
  sort_order: 0,
  branch_id: null,
  organization_id: "org-1",
  translations: {},
} as unknown as PaymentMethod;

const CARD: PaymentMethod = {
  ...CASH,
  id: "m-card",
  code: "card",
  name: "Card",
  requires_tendered: false,
} as unknown as PaymentMethod;

let itemSeq = 0;
function makeItem(over: Partial<CustomerOrderItem> = {}): CustomerOrderItem {
  itemSeq += 1;
  return {
    id: over.id ?? `item-${itemSeq}`,
    customer_order_id: "order-1",
    product_sku_id: `sku-${itemSeq}`,
    quantity: over.quantity ?? 1,
    unit_price: over.unit_price ?? 100_000,
    topping_subtotal: 0,
    subtotal: (over.quantity ?? 1) * (over.unit_price ?? 100_000),
    status: "pending",
    note: null,
    product_sku: {
      id: `sku-${itemSeq}`,
      name: `Món ${itemSeq}`,
      product: { id: `p-${itemSeq}`, name: `Món ${itemSeq}` },
    },
    ...over,
  } as CustomerOrderItem;
}

function makeOrder(
  items: CustomerOrderItem[],
  over: Partial<CustomerOrder> = {},
): CustomerOrder {
  const subtotal = items.reduce(
    (s, i) => s + Number(i.quantity) * Number(i.unit_price),
    0,
  );
  return {
    id: "order-1",
    order_code: "ORD-T-0001",
    order_type: "dine_in",
    status: "checkout",
    subtotal,
    discount_amount: 0,
    service_charge: 0,
    tax_amount: 0,
    total_amount: subtotal,
    paid_amount: 0,
    remaining_amount: String(subtotal),
    guest_count: 2,
    items,
    customer: null,
    customer_id: null,
    tables: [],
    ...over,
  } as unknown as CustomerOrder;
}

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

// ---------------------------------------------------------------------------
//  Shared query helpers
// ---------------------------------------------------------------------------

/** The cash box — one per row that has a `requires_tendered` method picked. */
function tenderFields(): HTMLElement[] {
  return Array.from(
    document.querySelectorAll<HTMLElement>('[data-slot="cash-tender-field"]'),
  );
}

function tenderInput(field: HTMLElement): HTMLInputElement {
  return within(field).getByRole("spinbutton") as HTMLInputElement;
}

function type(field: HTMLElement, value: string) {
  fireEvent.change(tenderInput(field), { target: { value } });
}

function personCards(): HTMLElement[] {
  return Array.from(
    document.querySelectorAll<HTMLElement>('[data-slot="person-payment-card"]'),
  );
}

function itemPaletteCards(): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>("li")).filter(
    (li) => !li.hasAttribute("data-slot") && !li.querySelector("li"),
  );
}

/** Radix Select opens on keyboard Enter under jsdom (pointerdown is a no-op). */
function pickMethodFromSelect(scope: HTMLElement, name: string) {
  const trigger = within(scope).getByRole("combobox");
  trigger.focus();
  fireEvent.keyDown(trigger, { key: "Enter" });
  fireEvent.click(screen.getByRole("option", { name: new RegExp(name) }));
}

function collectButtons(): HTMLElement[] {
  return screen.getAllByRole("button", { name: "Collect" });
}

// ===========================================================================
//  Chia theo món — SplitBillByItemsTab
// ===========================================================================

describe("chia theo món — per-guest cash", () => {
  function renderByItems(opts: {
    onCreatePayment?: ReturnType<typeof vi.fn>;
    onAllRowsPaid?: ReturnType<typeof vi.fn>;
    methods?: PaymentMethod[];
  } = {}) {
    const onCreatePayment =
      opts.onCreatePayment ?? vi.fn(() => Promise.resolve({ id: "pay-1" }));
    const onAllRowsPaid = opts.onAllRowsPaid ?? vi.fn();
    render(
      <SplitBillByItemsTab
        order={makeOrder([makeItem({ id: "a", unit_price: 100_000 })], {
          guest_count: 1,
        })}
        taxRate={0}
        serviceRate={0}
        methods={opts.methods ?? [CASH, CARD]}
        methodsLoading={false}
        onCreatePayment={onCreatePayment as never}
        onAllRowsPaid={onAllRowsPaid as never}
        onClose={vi.fn()}
      />,
      { wrapper: Wrapper },
    );
    fireEvent.click(itemPaletteCards()[0]!);
    return { onCreatePayment, onAllRowsPaid };
  }

  it("shows the cash box only after a requires_tendered method is picked", () => {
    renderByItems();
    expect(tenderFields()).toHaveLength(0);

    pickMethodFromSelect(personCards()[0]!, "Card");
    expect(tenderFields()).toHaveLength(0);

    pickMethodFromSelect(personCards()[0]!, "Cash");
    expect(tenderFields()).toHaveLength(1);
  });

  it("posts the cash the cashier typed and reports the change", async () => {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    const { onAllRowsPaid } = renderByItems({ onCreatePayment });

    pickMethodFromSelect(personCards()[0]!, "Cash");
    type(tenderFields()[0]!, "200000");
    // The change is stated inside the cash box itself, live, before Thu —
    // the figure the cashier counts out of the drawer.
    expect(within(tenderFields()[0]!).getByText("100.000 ₫")).toBeInTheDocument();

    fireEvent.click(collectButtons()[0]!);
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));

    const body = onCreatePayment.mock.calls[0]![0] as Record<string, unknown>;
    expect(body.amount).toBe(100_000);
    expect(body.tendered_amount).toBe(200_000);

    await waitFor(() => expect(onAllRowsPaid).toHaveBeenCalledTimes(1));
    const session = onAllRowsPaid.mock.calls[0]![0] as {
      guests: Array<{ tendered?: number; change?: number }>;
    };
    expect(session.guests[0]!.tendered).toBe(200_000);
    expect(session.guests[0]!.change).toBe(100_000);
  });

  it("still tenders the exact share when the box is left untouched", async () => {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderByItems({ onCreatePayment });

    pickMethodFromSelect(personCards()[0]!, "Cash");
    fireEvent.click(collectButtons()[0]!);

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const body = onCreatePayment.mock.calls[0]![0] as Record<string, unknown>;
    expect(body.tendered_amount).toBe(100_000);
  });

  it("refuses to collect a tender below the share", async () => {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderByItems({ onCreatePayment });

    pickMethodFromSelect(personCards()[0]!, "Cash");
    type(tenderFields()[0]!, "50000");

    expect(collectButtons()[0]!).toBeDisabled();
    fireEvent.click(collectButtons()[0]!);
    expect(onCreatePayment).not.toHaveBeenCalled();
  });

  it("sends no tender at all on a card row", async () => {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderByItems({ onCreatePayment });

    pickMethodFromSelect(personCards()[0]!, "Card");
    fireEvent.click(collectButtons()[0]!);

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const body = onCreatePayment.mock.calls[0]![0] as Record<string, unknown>;
    expect(body.tendered_amount).toBeUndefined();
  });

  it("drops a typed tender when the method is switched to card", async () => {
    // Otherwise a cash figure rides onto a card row and prints an お預かり
    // line for money that never changed hands.
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderByItems({ onCreatePayment });

    pickMethodFromSelect(personCards()[0]!, "Cash");
    type(tenderFields()[0]!, "500000");
    pickMethodFromSelect(personCards()[0]!, "Card");
    expect(tenderFields()).toHaveLength(0);

    fireEvent.click(collectButtons()[0]!);
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    expect(
      (onCreatePayment.mock.calls[0]![0] as Record<string, unknown>).tendered_amount,
    ).toBeUndefined();
  });

  it("keeps each guest's own tender — two guests, two different notes", async () => {
    const onCreatePayment = vi
      .fn()
      .mockResolvedValueOnce({ id: "pay-a" })
      .mockResolvedValueOnce({ id: "pay-b" });
    const onAllRowsPaid = vi.fn();
    render(
      <SplitBillByItemsTab
        order={makeOrder(
          [
            makeItem({ id: "a", unit_price: 100_000 }),
            makeItem({ id: "b", unit_price: 100_000 }),
          ],
          { guest_count: 2 },
        )}
        taxRate={0}
        serviceRate={0}
        methods={[CASH, CARD]}
        methodsLoading={false}
        onCreatePayment={onCreatePayment as never}
        onAllRowsPaid={onAllRowsPaid as never}
        onClose={vi.fn()}
      />,
      { wrapper: Wrapper },
    );

    fireEvent.click(itemPaletteCards()[0]!);
    fireEvent.click(personCards()[1]!);
    fireEvent.click(itemPaletteCards()[1]!);

    pickMethodFromSelect(personCards()[0]!, "Cash");
    type(tenderFields()[0]!, "200000");
    fireEvent.click(within(personCards()[0]!).getByRole("button", { name: "Collect" }));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));

    pickMethodFromSelect(personCards()[1]!, "Cash");
    type(tenderFields()[0]!, "500000"); // only guest 2's box is left
    fireEvent.click(within(personCards()[1]!).getByRole("button", { name: "Collect" }));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(2));

    expect(
      (onCreatePayment.mock.calls[0]![0] as Record<string, unknown>).tendered_amount,
    ).toBe(200_000);
    expect(
      (onCreatePayment.mock.calls[1]![0] as Record<string, unknown>).tendered_amount,
    ).toBe(500_000);

    await waitFor(() => expect(onAllRowsPaid).toHaveBeenCalledTimes(1));
    const session = onAllRowsPaid.mock.calls[0]![0] as {
      guests: Array<{ tendered?: number; change?: number }>;
    };
    expect(session.guests.map((g) => [g.tendered, g.change])).toEqual([
      [200_000, 100_000],
      [500_000, 400_000],
    ]);
  });
});

// ===========================================================================
//  Chia đều — SplitBillEvenTab
// ===========================================================================

describe("chia đều — per-guest cash", () => {
  function renderEqual(onCreatePayment: ReturnType<typeof vi.fn>, onAllRowsPaid = vi.fn()) {
    render(
      <SplitBillEvenTab
        open
        onOpenChange={vi.fn()}
        order={makeOrder([makeItem({ unit_price: 200_000 })], { guest_count: 2 })}
        methods={[CASH, CARD]}
        methodsLoading={false}
        splitData={{ per_person_amounts: [100_000, 100_000] } as never}
        splitLoading={false}
        splitError={null}
        splitCount={2}
        onChangeSplitCount={vi.fn()}
        onCreatePayment={onCreatePayment as never}
        onRefundPayment={vi.fn(() => Promise.resolve())}
        onCancelSplit={vi.fn()}
        onAllRowsPaid={onAllRowsPaid as never}
      />,
      { wrapper: Wrapper },
    );
    return { onAllRowsPaid };
  }

  it("posts each guest's own note and keeps them apart", async () => {
    // Chia đều is the case the amount-matched slip lookup could not tell
    // apart — both guests owe 100.000 ₫ and hand over different notes.
    const onCreatePayment = vi
      .fn()
      .mockResolvedValueOnce({ id: "pay-a" })
      .mockResolvedValueOnce({ id: "pay-b" });
    const { onAllRowsPaid } = renderEqual(onCreatePayment);

    const rows = screen.getAllByRole("listitem");
    pickMethodFromSelect(rows[0]!, "Cash");
    type(tenderFields()[0]!, "200000");
    fireEvent.click(within(rows[0]!).getByRole("button", { name: "Collect" }));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));

    const rows2 = screen.getAllByRole("listitem");
    pickMethodFromSelect(rows2[1]!, "Cash");
    type(tenderFields()[0]!, "500000");
    fireEvent.click(within(rows2[1]!).getByRole("button", { name: "Collect" }));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(2));

    const bodyA = onCreatePayment.mock.calls[0]![0] as Record<string, unknown>;
    const bodyB = onCreatePayment.mock.calls[1]![0] as Record<string, unknown>;
    expect(bodyA.amount).toBe(100_000);
    expect(bodyA.tendered_amount).toBe(200_000);
    expect(bodyB.amount).toBe(100_000);
    expect(bodyB.tendered_amount).toBe(500_000);

    await waitFor(() => expect(onAllRowsPaid).toHaveBeenCalledTimes(1));
    const session = onAllRowsPaid.mock.calls[0]![0] as {
      guests: Array<{ tendered?: number; change?: number }>;
    };
    expect(session.guests.map((g) => [g.tendered, g.change])).toEqual([
      [200_000, 100_000],
      [500_000, 400_000],
    ]);
  });

  it("refuses to collect a tender below the share", () => {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderEqual(onCreatePayment);

    const rows = screen.getAllByRole("listitem");
    pickMethodFromSelect(rows[0]!, "Cash");
    type(tenderFields()[0]!, "1000");

    const collect = within(screen.getAllByRole("listitem")[0]!).getByRole("button", {
      name: "Collect",
    });
    expect(collect).toBeDisabled();
    fireEvent.click(collect);
    expect(onCreatePayment).not.toHaveBeenCalled();
  });

  it("still tenders the exact share when the box is untouched", async () => {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderEqual(onCreatePayment);

    const rows = screen.getAllByRole("listitem");
    pickMethodFromSelect(rows[0]!, "Cash");
    fireEvent.click(within(rows[0]!).getByRole("button", { name: "Collect" }));

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    expect(
      (onCreatePayment.mock.calls[0]![0] as Record<string, unknown>).tendered_amount,
    ).toBe(100_000);
  });
});

// ===========================================================================
//  Chia theo số tiền — SplitBillByAmountTab
// ===========================================================================

describe("chia theo số tiền — per-guest cash", () => {
  function renderByAmount(onCreatePayment: ReturnType<typeof vi.fn>, onAllRowsPaid = vi.fn()) {
    render(
      <SplitBillByAmountTab
        order={makeOrder([makeItem({ unit_price: 200_000 })])}
        methods={[CASH, CARD]}
        methodsLoading={false}
        onCreatePayment={onCreatePayment as never}
        onAllRowsPaid={onAllRowsPaid as never}
        onClose={vi.fn()}
        currencyCode="VND"
      />,
      { wrapper: Wrapper },
    );
    // Two seeded rows; allocate the order across them so the footer balances
    // (a Thu is refused while the allocation does not add up).
    const amountInputs = screen.getAllByRole("spinbutton");
    fireEvent.change(amountInputs[0]!, { target: { value: "100000" } });
    fireEvent.change(screen.getAllByRole("spinbutton")[1]!, {
      target: { value: "100000" },
    });
    return { onAllRowsPaid };
  }

  /** This tab picks the method with plain buttons, not a Radix Select. */
  function pickMethodChip(card: HTMLElement, name: string) {
    fireEvent.click(within(card).getByRole("button", { name }));
  }

  function amountCards(): HTMLElement[] {
    return Array.from(
      document.querySelectorAll<HTMLElement>('[data-slot="card"]'),
    );
  }

  it("posts the typed note and carries tendered + change into the snapshot", async () => {
    const onCreatePayment = vi
      .fn()
      .mockResolvedValueOnce({ id: "pay-a" })
      .mockResolvedValueOnce({ id: "pay-b" });
    const { onAllRowsPaid } = renderByAmount(onCreatePayment);

    pickMethodChip(amountCards()[0]!, "Cash");
    type(tenderFields()[0]!, "200000");
    fireEvent.click(
      within(amountCards()[0]!).getByRole("button", { name: "Collect" }),
    );
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));

    pickMethodChip(amountCards()[1]!, "Cash");
    type(tenderFields()[0]!, "150000");
    fireEvent.click(
      within(amountCards()[1]!).getByRole("button", { name: "Collect" }),
    );
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(2));

    expect(
      (onCreatePayment.mock.calls[0]![0] as Record<string, unknown>).tendered_amount,
    ).toBe(200_000);
    expect(
      (onCreatePayment.mock.calls[1]![0] as Record<string, unknown>).tendered_amount,
    ).toBe(150_000);

    await waitFor(() => expect(onAllRowsPaid).toHaveBeenCalledTimes(1));
    const session = onAllRowsPaid.mock.calls[0]![0] as {
      guests: Array<{ tendered?: number; change?: number }>;
    };
    expect(session.guests.map((g) => [g.tendered, g.change])).toEqual([
      [200_000, 100_000],
      [150_000, 50_000],
    ]);
  });

  it("refuses to collect a tender below the share", () => {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderByAmount(onCreatePayment);

    pickMethodChip(amountCards()[0]!, "Cash");
    type(tenderFields()[0]!, "10000");

    const collect = within(amountCards()[0]!).getByRole("button", { name: "Collect" });
    expect(collect).toBeDisabled();
    fireEvent.click(collect);
    expect(onCreatePayment).not.toHaveBeenCalled();
  });

  it("sends no tender on a card row", async () => {
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderByAmount(onCreatePayment);

    pickMethodChip(amountCards()[0]!, "Card");
    expect(tenderFields()).toHaveLength(0);
    fireEvent.click(
      within(amountCards()[0]!).getByRole("button", { name: "Collect" }),
    );

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    expect(
      (onCreatePayment.mock.calls[0]![0] as Record<string, unknown>).tendered_amount,
    ).toBeUndefined();
  });
});
