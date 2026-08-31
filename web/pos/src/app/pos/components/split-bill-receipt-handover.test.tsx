/**
 * Split-bill → receipt-screen handover, under the teardown that actually
 * happens in a shop.
 *
 * THE BUG THIS PINS. A workstation broadcasts `order_paid` the instant the
 * closing payment commits, and `use-workstation-socket` answers it by
 * filtering that order out of the cached open-orders list — a direct cache
 * write with no round-trip, so it reliably reaches the browser BEFORE the POST
 * that caused it has resolved. `reconcileWithServer` then dropped the tab,
 * `activeOrder` went undefined, and every dialog behind page.tsx's
 * `activeOrder &&` guard unmounted — including the split-bill dialog that was
 * one beat from finishing. The final row's success was written to an unmounted
 * component, the `onAllRowsPaid` handover never fired, and the cashier landed
 * on the tables overview with no receipt screen and therefore no way to print
 * the hoá đơn đỏ for an order that had just been paid in full.
 *
 * The harness below is page.tsx's wiring for that path — real `usePosTabs`,
 * real `useReceiptFlow`, real dialogs — with the two server signals driven
 * SEPARATELY so the order between them is the test's to choose. Every test
 * here fires the open-list drop while the payment is still in flight, which is
 * the ordering production actually produces.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { SplitBillDialog } from "./split-bill-dialog";
import { SplitBillReceiptDialog } from "./split-bill-receipt-dialog";
import { usePosTabs } from "../hooks/use-pos-tabs";
import { useReceiptFlow } from "../hooks/use-receipt-flow";
import type { CustomerOrder, CustomerOrderItem, PaymentMethod } from "../types";

vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: {
    enabled: true,
    printPaymentReceipt: vi.fn(() => Promise.resolve()),
    printRedInvoice: vi.fn(() => Promise.resolve()),
    // #1875 — màn biên lai chia bill dò tally "đã in ×N" theo TỪNG khách khi mở.
    // Mock thiếu hàm này thì hook ném lỗi, DialogBoundary nuốt và hiện thẻ lỗi
    // thay vì màn biên lai — test đỏ với "expected null not to be null", không
    // hề nhắc tới print service. Công việc này viết TRƯỚC #69 nên chưa có nó.
    getPrintStatus: vi.fn(() => Promise.resolve({ red_invoice: undefined })),
  },
}));

beforeAll(() => {
  const proto = window.HTMLElement.prototype as unknown as Record<string, unknown>;
  proto.scrollIntoView = vi.fn();
  proto.hasPointerCapture = vi.fn(() => false);
  proto.setPointerCapture = vi.fn();
  proto.releasePointerCapture = vi.fn();
});

beforeEach(() => {
  localStorage.clear();
  // Pin the locale so the label assertions below are deterministic.
  localStorage.setItem("pos_locale", "en");
  server.posted = [];
  server.pending = [];
  server.dropFromOpenList = () => {};
});

// ---------------------------------------------------------------------------
//  Fixtures
// ---------------------------------------------------------------------------

const ORDER_ID = "order-1";

let itemSeq = 0;
function makeItem(over: Partial<CustomerOrderItem> = {}): CustomerOrderItem {
  itemSeq += 1;
  const quantity = over.quantity ?? 1;
  const unitPrice = over.unit_price ?? 100_000;
  return {
    id: over.id ?? `item-${itemSeq}`,
    customer_order_id: ORDER_ID,
    product_sku_id: `sku-${itemSeq}`,
    quantity,
    unit_price: unitPrice,
    topping_subtotal: 0,
    subtotal: quantity * unitPrice,
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

function makeOrder(items: CustomerOrderItem[]): CustomerOrder {
  const subtotal = items.reduce(
    (s, i) => s + Number(i.quantity) * Number(i.unit_price),
    0,
  );
  return {
    id: ORDER_ID,
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
    tables: [{ id: "tb-1", name: "B-08", code: "B08" }],
  } as unknown as CustomerOrder;
}

const cash: PaymentMethod = {
  id: "m-cash",
  code: "cash",
  name: "Cash",
  is_auto_confirm: true,
  // Cash is a tendered method in every real shop — the per-guest cash box
  // depends on it, and the handover must carry those figures through.
  requires_tendered: true,
  is_active: true,
  sort_order: 0,
  branch_id: null,
  organization_id: "org-1",
  translations: {},
} as unknown as PaymentMethod;

function Wrapper({ children }: { children: ReactNode }) {
  // `SplitBillDialog` chủ trì luồng thu bằng máy 釣銭機 (#2946), mà hook đó
  // đọc `useQueryClient` để làm tươi đơn sau khi máy ghi payment. Máy không
  // được ghép trong test nên không lượt thu nào chạy — nhưng hook vẫn được gọi,
  // nên client phải có mặt.
  const [qc] = useState(() => new QueryClient({ defaultOptions: { queries: { retry: false } } }));

  return (
    <QueryClientProvider client={qc}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

// ---------------------------------------------------------------------------
//  Harness — page.tsx's split-bill wiring, with the server signals split apart
// ---------------------------------------------------------------------------

/**
 * The "server" is test-owned, module-scope state so the harness never mutates
 * anything React holds. Two signals, driven independently:
 *   - `dropFromOpenList()` = the workstation's `order_paid` cache patch
 *   - `resolveNextPayment()` = the POST response finally coming back
 */
const server = {
  posted: [] as Array<{ amount: number }>,
  pending: [] as Array<(id: string) => void>,
  dropFromOpenList: () => {},
};

function resolveNextPayment(id: string) {
  server.pending.shift()?.(id);
}

function Harness({ order }: { order: CustomerOrder }) {
  const { activeTab, createTab, closeTab, reconcileWithServer, tabs } = usePosTabs();
  const [openOrderIds, setOpenOrderIds] = useState<string[]>([ORDER_ID]);
  const [splitBillOpen, setSplitBillOpen] = useState(false);
  const [paymentOpen] = useState(false);

  // One order tab, opened + selected, split-bill dialog up. Mirrors a cashier
  // who tapped the table then "Chia bill".
  useEffect(() => {
    createTab(ORDER_ID, "ORD-T-0001");
    setSplitBillOpen(true);
    server.dropFromOpenList = () => setOpenOrderIds([]);
  }, [createTab]);

  const activeOrder = activeTab?.orderId === ORDER_ID ? order : undefined;

  const receipts = useReceiptFlow({
    activeOrder,
    activeTab,
    closeTab,
    closeSplitBillDialog: () => setSplitBillOpen(false),
  });

  // page.tsx wires exactly this — via useTabSync, inlined here so the harness
  // owns the open-order feed.
  const keepOrderIds = useMemo(() => {
    const ids = new Set<string>();
    if ((paymentOpen || splitBillOpen) && activeTab) ids.add(activeTab.orderId);
    if (receipts.paymentReceipt) ids.add(receipts.paymentReceipt.orderId);
    if (receipts.splitBillReceipt) ids.add(receipts.splitBillReceipt.orderId);
    return ids;
  }, [
    paymentOpen,
    splitBillOpen,
    activeTab,
    receipts.paymentReceipt,
    receipts.splitBillReceipt,
  ]);

  useEffect(() => {
    reconcileWithServer(new Set(openOrderIds), keepOrderIds);
  }, [openOrderIds, reconcileWithServer, keepOrderIds]);

  function onCreatePayment(body: { amount: number }) {
    server.posted.push({ amount: body.amount });
    return new Promise<{ id: string }>((resolve) => {
      server.pending.push((id: string) => resolve({ id }));
    });
  }

  return (
    <>
      <div data-testid="tab-count">{tabs.length}</div>
      {activeOrder && (
        <SplitBillDialog
          open={splitBillOpen}
          onOpenChange={setSplitBillOpen}
          order={activeOrder}
          methods={[cash]}
          methodsLoading={false}
          splitData={null}
          splitLoading={false}
          splitError={null}
          splitCount={2}
          onChangeSplitCount={() => {}}
          onCreatePayment={onCreatePayment as never}
          onRefundPayment={() => Promise.resolve()}
          onCancelSplit={() => {}}
          onAllRowsPaid={receipts.captureSplitBillReceipt}
          serviceChargeRate={0}
        />
      )}
      {receipts.splitBillReceipt && (
        <SplitBillReceiptDialog
          open={true}
          onOpenChange={(o) => {
            if (!o) receipts.completeSplitBillReceipt([]);
          }}
          data={receipts.splitBillReceipt.data}
          orderId={receipts.splitBillReceipt.orderId}
          customerId={receipts.splitBillReceipt.customerId}
          customerName={receipts.splitBillReceipt.customerName}
          onComplete={receipts.completeSplitBillReceipt}
        />
      )}
    </>
  );
}

// ---------------------------------------------------------------------------
//  Interaction helpers (same jsdom recipes as split-bill-by-items-tab.test)
// ---------------------------------------------------------------------------

function personCards(): HTMLElement[] {
  return Array.from(
    document.querySelectorAll<HTMLElement>('[data-slot="person-payment-card"]'),
  );
}

function itemPaletteCards(): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>("li")).filter(
    (li) => !li.hasAttribute("data-slot"),
  );
}

function pickMethod(card: HTMLElement, methodName: string) {
  const trigger = within(card).getByRole("combobox");
  trigger.focus();
  fireEvent.keyDown(trigger, { key: "Enter" });
  fireEvent.click(screen.getByRole("option", { name: new RegExp(methodName) }));
}

function collect(card: HTMLElement) {
  fireEvent.click(within(card).getByRole("button", { name: "Collect" }));
}

/** The per-guest cash box, present once a tendered method is picked. */
function cashBoxes(): HTMLElement[] {
  return Array.from(
    document.querySelectorAll<HTMLElement>('[data-slot="cash-tender-field"]'),
  );
}

/** The split-bill receipt screen, identified by its own title. */
function receiptScreen(): HTMLElement | null {
  return screen.queryByText("Payment successful");
}

// ---------------------------------------------------------------------------
//  Tests
// ---------------------------------------------------------------------------

describe("split-bill by items → receipt handover survives the order_paid teardown", () => {
  it("shows the receipt screen when the open-list drop beats the POST response", async () => {
    const order = makeOrder([
      makeItem({ id: "a", unit_price: 100_000 }),
      makeItem({ id: "b", unit_price: 50_000 }),
    ]);

    render(<Harness order={order} />, { wrapper: Wrapper });

    await screen.findByRole("tab", { name: "By items" });
    fireEvent.click(screen.getByRole("tab", { name: "By items" }));

    // Person 1 takes item a, person 2 takes item b — the whole order allocated.
    fireEvent.click(itemPaletteCards()[0]!);
    fireEvent.click(personCards()[1]!);
    fireEvent.click(itemPaletteCards()[1]!);

    // Collect from person 1.
    pickMethod(personCards()[0]!, "Cash");
    collect(personCards()[0]!);
    resolveNextPayment("pay-a");
    await waitFor(() =>
      expect(within(personCards()[0]!).getByText("Paid")).toBeInTheDocument(),
    );

    // Collect from person 2 — the payment that closes the order.
    pickMethod(personCards()[1]!, "Cash");
    collect(personCards()[1]!);

    // The workstation commits, broadcasts `order_paid`, and pos-web drops the
    // order from the open list — all BEFORE the POST response lands. This is
    // the ordering that used to unmount the dialog mid-flow.
    act(() => server.dropFromOpenList());
    await waitFor(() => expect(server.posted).toHaveLength(2));
    resolveNextPayment("pay-b");

    // The cashier gets the receipt screen, not the tables overview.
    await waitFor(() => expect(receiptScreen()).not.toBeNull());

    // …carrying both guests, the money actually taken, and the hoá đơn đỏ CTAs
    // this bug made unreachable — now one PER GUEST and nothing order-level.
    expect(screen.getByText("ORD-T-0001")).toBeInTheDocument();
    expect(screen.getByText("Guest 1")).toBeInTheDocument();
    expect(screen.getByText("Guest 2")).toBeInTheDocument();
    // Grand total: header figure + the "collected" figure.
    expect(screen.getAllByText("150.000 ₫")).toHaveLength(2);
    expect(
      screen.getByRole("button", { name: "Payment receipt for guest 1" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Payment receipt for guest 2" }),
    ).toBeInTheDocument();
    // #1939 — the order-level CTA is gone on purpose. A split bill has no single
    // payer to hand a whole-table tax document to, and this was the widest
    // button on the screen sitting directly under the per-guest ones.
    //
    // #2070 — the label follows the PAPER (`pos.red_invoice.cta`), which #2062
    // renamed to "PAYMENT RECEIPT" in en. Assert the current string, not the
    // retired "Print red invoice": a queryBy on a label nothing renders any
    // more passes for the wrong reason and stops guarding this.
    expect(
      screen.queryByRole("button", { name: "Print payment receipt" }),
    ).toBeNull();
  });

  it("keeps the order tab open until the cashier dismisses the receipt", async () => {
    const order = makeOrder([makeItem({ id: "a", unit_price: 100_000 })]);

    render(<Harness order={order} />, { wrapper: Wrapper });

    await screen.findByRole("tab", { name: "By items" });
    fireEvent.click(screen.getByRole("tab", { name: "By items" }));

    // One payer takes the whole order.
    fireEvent.click(itemPaletteCards()[0]!);
    pickMethod(personCards()[0]!, "Cash");
    collect(personCards()[0]!);

    act(() => server.dropFromOpenList());
    await waitFor(() => expect(server.posted).toHaveLength(1));
    resolveNextPayment("pay-a");

    await waitFor(() => expect(receiptScreen()).not.toBeNull());
    // Tab still there — the receipt screen owns the close.
    expect(screen.getByTestId("tab-count")).toHaveTextContent("1");

    fireEvent.click(screen.getByRole("button", { name: "Done" }));

    await waitFor(() => expect(receiptScreen()).toBeNull());
    await waitFor(() =>
      expect(screen.getByTestId("tab-count")).toHaveTextContent("0"),
    );
  });
});

describe("the cash each guest handed over survives the handover", () => {
  it("carries per-guest tendered + change onto the receipt screen", async () => {
    // End to end for the feature: keystroke in the split tab → payment body →
    // completion snapshot → receipt screen, across the same `order_paid`
    // teardown the tests above cover. Two guests owing the SAME amount hand
    // over DIFFERENT notes — the case a shared figure would hide.
    const order = makeOrder([
      makeItem({ id: "a", unit_price: 100_000 }),
      makeItem({ id: "b", unit_price: 100_000 }),
    ]);

    render(<Harness order={order} />, { wrapper: Wrapper });

    await screen.findByRole("tab", { name: "By items" });
    fireEvent.click(screen.getByRole("tab", { name: "By items" }));

    fireEvent.click(itemPaletteCards()[0]!);
    fireEvent.click(personCards()[1]!);
    fireEvent.click(itemPaletteCards()[1]!);

    pickMethod(personCards()[0]!, "Cash");
    fireEvent.change(
      within(cashBoxes()[0]!).getByRole("spinbutton"),
      { target: { value: "500000" } },
    );
    collect(personCards()[0]!);
    resolveNextPayment("pay-a");
    await waitFor(() =>
      expect(within(personCards()[0]!).getByText("Paid")).toBeInTheDocument(),
    );

    pickMethod(personCards()[1]!, "Cash");
    fireEvent.change(
      within(cashBoxes()[0]!).getByRole("spinbutton"),
      { target: { value: "300000" } },
    );
    collect(personCards()[1]!);

    act(() => server.dropFromOpenList());
    await waitFor(() => expect(server.posted).toHaveLength(2));
    resolveNextPayment("pay-b");

    await waitFor(() => expect(receiptScreen()).not.toBeNull());

    // Guest 1 gave 500k on a 100k share → 400k back. Guest 2 gave 300k → 200k.
    const rows = screen.getAllByRole("button", { name: /Guest \d/ });
    expect(within(rows[0]!).getByText("500.000 ₫")).toBeInTheDocument();
    expect(within(rows[0]!).getByText("400.000 ₫")).toBeInTheDocument();
    expect(within(rows[1]!).getByText("300.000 ₫")).toBeInTheDocument();
    expect(within(rows[1]!).getByText("200.000 ₫")).toBeInTheDocument();
  });
});
