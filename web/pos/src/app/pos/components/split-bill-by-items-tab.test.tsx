/**
 * SplitBillByItemsTab — plan-021 chia-theo-món cash-surface tests.
 *
 * Test-gap coverage (plan-audit):
 *   Gap 1 — the by-items UI (S2/S3) shipped with ZERO component tests on the
 *           cash surface. This drives the real component: guest_count → person
 *           cards, item-palette allocation, live per-bill money math, the
 *           "no valid items" short-circuit, and the confirm gate.
 *   Gap 2 — no idempotency-replay + concurrency/double-claim test existed in
 *           the plan-021 suite. These assert the `inFlightRef` synchronous lock
 *           (a rapid double-tap on "Thu" fires exactly ONE POST), the stable
 *           idempotency_key reused across a retry, and the key RESET when staff
 *           changes method on a failed row.
 *
 * Money math is asserted EXACTLY against the captured POST body — no fuzzy
 * currency-string matching.
 */

import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { SplitBillByItemsTab } from "./split-bill-by-items-tab";
import type { CustomerOrder, CustomerOrderItem, PaymentMethod } from "../types";

// ---------------------------------------------------------------------------
//  jsdom pointer polyfills — Radix Select needs these to open in jsdom.
// ---------------------------------------------------------------------------

beforeAll(() => {
  const proto = window.HTMLElement.prototype as unknown as Record<string, unknown>;
  proto.scrollIntoView = vi.fn();
  proto.hasPointerCapture = vi.fn(() => false);
  proto.setPointerCapture = vi.fn();
  proto.releasePointerCapture = vi.fn();
});

// Pin locale so string assertions are deterministic (test env has no
// VITE_DEFAULT_LOCALE → AppProvider would default to "ja"). Runs after the
// shared setup's storage.clear().
beforeEach(() => {
  localStorage.setItem("pos_locale", "en");
});

afterEach(() => {
  vi.clearAllMocks();
});

// ---------------------------------------------------------------------------
//  Fixture builders
// ---------------------------------------------------------------------------

let itemSeq = 0;

function makeItem(over: Partial<CustomerOrderItem> = {}): CustomerOrderItem {
  itemSeq += 1;
  const quantity = over.quantity ?? 1;
  const unitPrice = over.unit_price ?? 100_000;
  const toppingSubtotal = over.topping_subtotal ?? 0;
  return {
    id: over.id ?? `item-${itemSeq}`,
    customer_order_id: "order-1",
    product_sku_id: `sku-${itemSeq}`,
    quantity,
    unit_price: unitPrice,
    topping_subtotal: toppingSubtotal,
    subtotal: quantity * unitPrice + toppingSubtotal,
    status: over.status ?? "pending",
    note: null,
    served_at: null,
    voided_at: null,
    void_reason: null,
    created_at: null,
    updated_at: null,
    product_sku: {
      id: `sku-${itemSeq}`,
      name: over.product_sku?.name ?? `Món ${itemSeq}`,
      product: { id: `prod-${itemSeq}`, name: over.product_sku?.product?.name ?? `Món ${itemSeq}` },
    },
    ...over,
  } as CustomerOrderItem;
}

function makeOrder(items: CustomerOrderItem[], over: Partial<CustomerOrder> = {}): CustomerOrder {
  const valid = items.filter((i) => i.status !== "voided");
  const subtotal = valid.reduce(
    (s, i) => s + Number(i.quantity) * Number(i.unit_price) + Number(i.topping_subtotal ?? 0),
    0,
  );
  const discount = Number(over.discount_amount ?? 0);
  const taxable = Math.max(0, subtotal - discount);
  return {
    id: "order-1",
    order_code: "ORD-T-0001",
    order_type: "dine_in",
    status: "checkout",
    subtotal,
    discount_amount: discount,
    service_charge: 0,
    tax_amount: 0,
    total_amount: taxable, // caller usually overrides once tax/service known
    paid_amount: 0,
    total_tip: 0,
    remaining_amount: String(taxable),
    guest_count: over.guest_count ?? 2,
    items,
    branch_id: "branch-1",
    brand_id: "brand-1",
    organization_id: "org-1",
    ...over,
  } as unknown as CustomerOrder;
}

function makeMethod(over: Partial<PaymentMethod> = {}): PaymentMethod {
  return {
    id: over.id ?? "m-cash",
    code: over.code ?? "cash",
    name: over.name ?? "Cash",
    is_auto_confirm: over.is_auto_confirm ?? true,
    requires_tendered: over.requires_tendered ?? false,
    is_active: over.is_active ?? true,
    sort_order: 0,
    branch_id: null,
    organization_id: "org-1",
    translations: {},
    created_at: null,
    updated_at: null,
    deleted_at: null,
  } as PaymentMethod;
}

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

interface RenderOpts {
  order: CustomerOrder;
  methods?: PaymentMethod[];
  taxRate?: number;
  serviceRate?: number;
  onCreatePayment?: (body: unknown) => Promise<{ id: string }>;
  onAllRowsPaid?: (data: unknown) => void;
  onClose?: () => void;
}

function renderTab(opts: RenderOpts) {
  const onCreatePayment =
    opts.onCreatePayment ?? vi.fn(() => Promise.resolve({ id: "pay-1" }));
  const onAllRowsPaid = opts.onAllRowsPaid ?? vi.fn();
  const onClose = opts.onClose ?? vi.fn();
  const utils = render(
    <SplitBillByItemsTab
      order={opts.order}
      taxRate={opts.taxRate ?? 0}
      serviceRate={opts.serviceRate ?? 0}
      methods={opts.methods ?? [makeMethod()]}
      methodsLoading={false}
      onCreatePayment={onCreatePayment as never}
      onAllRowsPaid={onAllRowsPaid as never}
      onClose={onClose}
    />,
    { wrapper: Wrapper },
  );
  return { ...utils, onCreatePayment, onAllRowsPaid, onClose };
}

// ---------------------------------------------------------------------------
//  Interaction helpers
// ---------------------------------------------------------------------------

/** The person cards on the LEFT — one <li data-slot="person-payment-card">. */
function personCards(): HTMLElement[] {
  return Array.from(
    document.querySelectorAll<HTMLElement>('[data-slot="person-payment-card"]'),
  );
}

/** The item palette cards on the RIGHT. Clicking an unassigned one assigns a
 *  unit to the active person. Returns them in document order. */
function itemPaletteCards(): HTMLElement[] {
  // Palette cards live in the RIGHT column <ul>; each is an <li>. The person
  // cards carry data-slot so we can exclude them.
  const lis = Array.from(document.querySelectorAll<HTMLElement>("li"));
  return lis.filter((li) => !li.hasAttribute("data-slot"));
}

/** Open the Radix Select inside a given person card and pick a method by name.
 *  jsdom-safe recipe: Radix Select opens on keyboard Enter (pointerdown is a
 *  no-op under jsdom), then the option is selectable by click. */
function pickMethod(card: HTMLElement, methodName: string) {
  const trigger = within(card).getByRole("combobox");
  trigger.focus();
  fireEvent.keyDown(trigger, { key: "Enter" });
  // Radix portals the listbox to document.body.
  const option = screen.getByRole("option", { name: new RegExp(methodName) });
  fireEvent.click(option);
}

function payButton(card: HTMLElement): HTMLButtonElement {
  return within(card).getByRole("button", { name: "Collect" }) as HTMLButtonElement;
}

// ---------------------------------------------------------------------------
//  Gap 1 — cash-surface UI
// ---------------------------------------------------------------------------

describe("SplitBillByItemsTab — render / allocation (Gap 1)", () => {
  it("renders one person card per split-count (default = guest_count)", () => {
    const order = makeOrder([makeItem()], { guest_count: 3, total_amount: 100_000 });
    renderTab({ order });
    expect(personCards()).toHaveLength(3);
  });

  it("shows the 'no valid items' alert + only a Close button when every line is voided", () => {
    const order = makeOrder([makeItem({ status: "voided", unit_price: 999 })], {
      guest_count: 2,
      total_amount: 0,
    });
    renderTab({ order });
    expect(screen.getByText("No valid items to split")).toBeInTheDocument();
    expect(personCards()).toHaveLength(0);
    expect(screen.getByRole("button", { name: "Close" })).toBeInTheDocument();
  });

  it("assigning an item to the active person updates that bill's live total (money exact)", () => {
    // 1 item 100k, tax 10%, service 5% → bill 0 total = 100k + 10k + 5k = 115k.
    const item = makeItem({ unit_price: 100_000 });
    const order = makeOrder([item], { guest_count: 2, total_amount: 115_000 });
    renderTab({ order, taxRate: 10, serviceRate: 5 });

    // Before assignment both bills read 0 ₫ (VND, 0 fraction digits).
    const cardsBefore = personCards();
    expect(within(cardsBefore[0]!).getByText("0 ₫")).toBeInTheDocument();

    // Assign the item to person 0 (active by default) — click the palette card.
    fireEvent.click(itemPaletteCards()[0]!);

    // Person card 0 now shows the 115.000 ₫ total; person 1 stays at 0.
    const cardsAfter = personCards();
    expect(within(cardsAfter[0]!).getByText("115.000 ₫")).toBeInTheDocument();
    expect(within(cardsAfter[1]!).getByText("0 ₫")).toBeInTheDocument();
  });

  it("keeps the 'confirm' button disabled until every unit is assigned AND paid", () => {
    const item = makeItem({ unit_price: 100_000 });
    const order = makeOrder([item], { guest_count: 2, total_amount: 100_000 });
    renderTab({ order });

    const confirm = screen.getByRole("button", { name: "Confirm payment" });
    expect(confirm).toBeDisabled();

    // Assign but don't pay → still disabled (unpaid bill).
    fireEvent.click(itemPaletteCards()[0]!);
    expect(confirm).toBeDisabled();
  });

  it("surfaces the unassigned-unit badge while units remain unallocated", () => {
    const order = makeOrder([makeItem(), makeItem()], {
      guest_count: 2,
      total_amount: 200_000,
    });
    renderTab({ order });
    // 2 items, both unassigned → "Còn 2 món chưa gán".
    expect(screen.getByText(/2 unit\(s\) unassigned/)).toBeInTheDocument();

    fireEvent.click(itemPaletteCards()[0]!);
    expect(screen.getByText(/1 unit\(s\) unassigned/)).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
//  Gap 1 — payment payload (money math + by-items metadata)
// ---------------------------------------------------------------------------

describe("SplitBillByItemsTab — payment payload (Gap 1)", () => {
  it("posts the exact per-bill amount + by_items metadata for the paid person", async () => {
    const item = makeItem({ id: "pho", unit_price: 100_000 });
    const order = makeOrder([item], { guest_count: 2, total_amount: 115_000 });
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-A" }));
    renderTab({ order, taxRate: 10, serviceRate: 5, onCreatePayment });

    fireEvent.click(itemPaletteCards()[0]!); // assign phở → person 0

    const card0 = personCards()[0]!;
    pickMethod(card0, "Cash");
    fireEvent.click(payButton(card0));

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const body = onCreatePayment.mock.calls[0]![0] as Record<string, unknown>;

    // Money EXACT: 100k subtotal + 10% tax + 5% service = 115k.
    expect(body.amount).toBe(115_000);
    expect(body.payment_method_id).toBe("m-cash");
    expect(body.expected_total_amount).toBe(115_000);
    expect(body.metadata).toMatchObject({
      split_mode: "by_items",
      bill_index: 0,
      total_bills: 1,
      item_allocations: [{ item_id: "pho", units: 1 }],
    });
    // Idempotency key present + non-empty.
    expect(typeof body.idempotency_key).toBe("string");
    expect((body.idempotency_key as string).length).toBeGreaterThan(0);
  });

  it("does not send tendered_amount for an auto-confirm cash method", async () => {
    const order = makeOrder([makeItem({ unit_price: 50_000 })], {
      guest_count: 1,
      total_amount: 50_000,
    });
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderTab({ order, onCreatePayment });

    fireEvent.click(itemPaletteCards()[0]!);
    const card0 = personCards()[0]!;
    pickMethod(card0, "Cash");
    fireEvent.click(payButton(card0));

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const body = onCreatePayment.mock.calls[0]![0] as Record<string, unknown>;
    expect(body.tendered_amount).toBeUndefined();
    expect(body.amount).toBe(50_000);
  });

  it("sends tendered_amount === amount for a requires-tendered method", async () => {
    const order = makeOrder([makeItem({ unit_price: 50_000 })], {
      guest_count: 1,
      total_amount: 50_000,
    });
    const method = makeMethod({ id: "m-cash2", requires_tendered: true, name: "Cash" });
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderTab({ order, methods: [method], onCreatePayment });

    fireEvent.click(itemPaletteCards()[0]!);
    const card0 = personCards()[0]!;
    pickMethod(card0, "Cash");
    fireEvent.click(payButton(card0));

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const body = onCreatePayment.mock.calls[0]![0] as Record<string, unknown>;
    expect(body.tendered_amount).toBe(50_000);
  });
});

// ---------------------------------------------------------------------------
//  Gap 2 — concurrency / double-claim + idempotency-replay
// ---------------------------------------------------------------------------

describe("SplitBillByItemsTab — concurrency + idempotency (Gap 2)", () => {
  it("double-tapping 'Thu' fires exactly ONE POST (inFlightRef lock)", async () => {
    const order = makeOrder([makeItem({ unit_price: 100_000 })], {
      guest_count: 1,
      total_amount: 100_000,
    });
    // Never-resolving promise so both synchronous clicks land while the first
    // is still in-flight — the lock must reject the second.
    let resolveFirst!: (v: { id: string }) => void;
    const onCreatePayment = vi.fn(
      () => new Promise<{ id: string }>((res) => (resolveFirst = res)),
    );
    renderTab({ order, onCreatePayment });

    fireEvent.click(itemPaletteCards()[0]!);
    const card0 = personCards()[0]!;
    pickMethod(card0, "Cash");

    const btn = payButton(card0);
    fireEvent.click(btn);
    fireEvent.click(btn); // rapid second tap before the first resolves

    expect(onCreatePayment).toHaveBeenCalledTimes(1);
    resolveFirst({ id: "pay-1" });
    await waitFor(() =>
      expect(within(card0).getByText("Paid")).toBeInTheDocument(),
    );
  });

  it("a retry after a failed POST reuses the SAME idempotency_key", async () => {
    const order = makeOrder([makeItem({ unit_price: 100_000 })], {
      guest_count: 1,
      total_amount: 100_000,
    });
    const onCreatePayment = vi
      .fn()
      .mockRejectedValueOnce(new Error("network glitch"))
      .mockResolvedValueOnce({ id: "pay-1" });
    renderTab({ order, onCreatePayment });

    fireEvent.click(itemPaletteCards()[0]!);
    const card0 = personCards()[0]!;
    pickMethod(card0, "Cash");

    // Attempt 1 → fails.
    fireEvent.click(payButton(card0));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    // Row shows the failed error text.
    await waitFor(() =>
      expect(within(card0).getByText("network glitch")).toBeInTheDocument(),
    );

    // Attempt 2 (retry, same method) → succeeds.
    fireEvent.click(payButton(personCards()[0]!));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(2));

    const key1 = (onCreatePayment.mock.calls[0]![0] as Record<string, unknown>)
      .idempotency_key;
    const key2 = (onCreatePayment.mock.calls[1]![0] as Record<string, unknown>)
      .idempotency_key;
    expect(typeof key1).toBe("string");
    expect(key2).toBe(key1); // replay-safe: same key on retry
  });

  it("changing method on a failed row RESETS the idempotency key (new attempt)", async () => {
    const order = makeOrder([makeItem({ unit_price: 100_000 })], {
      guest_count: 1,
      total_amount: 100_000,
    });
    const methods = [
      makeMethod({ id: "m-cash", code: "cash", name: "Cash" }),
      makeMethod({ id: "m-card", code: "card", name: "Card" }),
    ];
    const onCreatePayment = vi
      .fn()
      .mockRejectedValueOnce(new Error("declined"))
      .mockResolvedValueOnce({ id: "pay-1" });
    renderTab({ order, methods, onCreatePayment });

    fireEvent.click(itemPaletteCards()[0]!);
    let card0 = personCards()[0]!;
    pickMethod(card0, "Cash");

    // Attempt 1 with cash → fails.
    fireEvent.click(payButton(card0));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    await waitFor(() =>
      expect(within(personCards()[0]!).getByText("declined")).toBeInTheDocument(),
    );

    // Switch method to card → key resets.
    card0 = personCards()[0]!;
    pickMethod(card0, "Card");
    fireEvent.click(payButton(personCards()[0]!));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(2));

    const key1 = (onCreatePayment.mock.calls[0]![0] as Record<string, unknown>)
      .idempotency_key;
    const key2 = (onCreatePayment.mock.calls[1]![0] as Record<string, unknown>)
      .idempotency_key;
    expect(key2).not.toBe(key1); // fresh attempt after method change
    expect(onCreatePayment.mock.calls[1]![0]).toMatchObject({
      payment_method_id: "m-card",
    });
  });

  it("a successfully-paid row cannot be re-charged (frozen bill)", async () => {
    const order = makeOrder([makeItem({ unit_price: 100_000 })], {
      guest_count: 1,
      total_amount: 100_000,
    });
    const onCreatePayment = vi.fn(() => Promise.resolve({ id: "pay-1" }));
    renderTab({ order, onCreatePayment });

    fireEvent.click(itemPaletteCards()[0]!);
    const card0 = personCards()[0]!;
    pickMethod(card0, "Cash");
    fireEvent.click(payButton(card0));

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    await waitFor(() =>
      expect(within(personCards()[0]!).getByText("Paid")).toBeInTheDocument(),
    );

    // The Pay button + method Select are gone once succeeded — no way to
    // re-POST. Assert the "Thu" control no longer exists in the card.
    expect(within(personCards()[0]!).queryByRole("button", { name: "Collect" })).toBeNull();
    expect(onCreatePayment).toHaveBeenCalledTimes(1);
  });

  it("fires onAllRowsPaid once every non-empty bill is settled", async () => {
    // 2 items, 2 people, one item each → both bills non-empty.
    const a = makeItem({ id: "a", unit_price: 100_000 });
    const b = makeItem({ id: "b", unit_price: 50_000 });
    const order = makeOrder([a, b], { guest_count: 2, total_amount: 150_000 });
    const onCreatePayment = vi
      .fn()
      .mockResolvedValueOnce({ id: "pay-a" })
      .mockResolvedValueOnce({ id: "pay-b" });
    const onAllRowsPaid = vi.fn();
    renderTab({ order, onCreatePayment, onAllRowsPaid });

    // Person 0 gets item a.
    fireEvent.click(itemPaletteCards()[0]!);
    // Activate person 1, give them item b.
    fireEvent.click(personCards()[1]!);
    fireEvent.click(itemPaletteCards()[1]!);

    // Pay person 0.
    let cards = personCards();
    pickMethod(cards[0]!, "Cash");
    fireEvent.click(payButton(cards[0]!));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));

    // Pay person 1 → last non-empty bill → onAllRowsPaid fires.
    cards = personCards();
    pickMethod(cards[1]!, "Cash");
    fireEvent.click(payButton(cards[1]!));
    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(onAllRowsPaid).toHaveBeenCalledTimes(1));

    const session = onAllRowsPaid.mock.calls[0]![0] as {
      mode: string;
      guestCount: number;
      totalAmount: number;
      guests: Array<{ amount: number }>;
    };
    expect(session.mode).toBe("by_items");
    expect(session.guestCount).toBe(2);
    // Σ guests === order total (money exact, no tax/service here).
    expect(session.totalAmount).toBe(150_000);
    expect(session.guests.map((g) => g.amount).sort((x, y) => x - y)).toEqual([
      50_000, 100_000,
    ]);
  });
});
