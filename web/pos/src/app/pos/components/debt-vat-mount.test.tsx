/**
 * Issue #683 — the debt CTA + post-payment print CTAs shipped fully built but were
 * never mounted, so two of plan-038's five capabilities were unreachable from
 * the UI. These tests pin the mount points down: if a future refactor drops a
 * CTA, or re-breaks the walk-in gate on the DEBT flow that mirrors the
 * backend's `422 customer_required_for_debt`, this file fails.
 *
 * Labels are asserted in Japanese because AppProvider's default locale is `ja`.
 */

import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { OrderCart, type OrderCartProps } from "./order-cart";
import { PaymentDialog } from "./payment-dialog";
import { PaymentReceiptDialog } from "./payment-receipt-dialog";
import { PrintResultDialog } from "./print-result-dialog";
import { workstationPrintService } from "@/services/workstation-print-service";
import type { CustomerOrder, EffectivePaymentOption, PaymentMethod } from "../types";

vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: {
    printDebtSlip: vi.fn(() => Promise.resolve()),
    printPaymentReceipt: vi.fn(() => Promise.resolve()),
  },
}));

const printMock = vi.mocked(workstationPrintService);

// The debt CTA reads /pos/payment-methods itself — `on_account` exists in no
// other list, which is exactly the bug this file's CTA cases guard. Mock the
// hook rather than the transport so these stay tests of the DIALOGS.
vi.mock("@/hooks/api/use-shop-payment-methods", () => ({
  useShopPaymentMethods: () => ({ data: [cash, debtMethod] }),
}));

// PaymentDialog uses useCardTerminal (react-query) for the P400 flow.
const testQueryClient = new QueryClient();

function Wrapper({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={testQueryClient}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

const cash: PaymentMethod = {
  id: "pm-cash",
  code: "cash",
  name: "Cash",
  type: "cash",
  is_auto_confirm: true,
  requires_tendered: true,
  is_active: true,
  sort_order: 0,
  branch_id: null,
  organization_id: "org-1",
  translations: {},
  created_at: null,
  updated_at: null,
  deleted_at: null,
} as unknown as PaymentMethod;

/** The seeded on_account row — plan-038 seeds code=debt AND type=on_account. */
const debtMethod: PaymentMethod = {
  ...cash,
  id: "pm-debt",
  code: "debt",
  name: "Ghi nợ",
  type: "on_account",
  requires_tendered: false,
} as unknown as PaymentMethod;

const cashOption: EffectivePaymentOption = {
  id: "opt-cash",
  display_name: "Cash",
  provider: "internal",
  rail: "cash",
  method_type: "cash",
  effective: true,
  source: "shop",
  reason: "",
  error_code: null,
  connection_id: "conn-1",
  connection_option_id: null,
  shop_option_id: "shop-opt-cash",
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

const debtOption: EffectivePaymentOption = {
  id: "opt-debt",
  display_name: "Ghi nợ",
  provider: "internal",
  rail: "on_account",
  method_type: "on_account",
  effective: true,
  source: "shop",
  reason: "",
  error_code: null,
  connection_id: null,
  connection_option_id: null,
  shop_option_id: "shop-opt-debt",
  shop_preference: "inherit",
  device_preference: "inherit",
  legacy_payment_method_id: "pm-debt",
  legacy_payment_method_code: "debt",
  client: {
    requires_tendered: false,
    immediate_settlement: true,
    supports_pos_checkout: false,
  },
};

function makeOrder(over: Partial<CustomerOrder> = {}): CustomerOrder {
  return {
    id: "ord-1",
    order_code: "ORD-1",
    total_amount: 10000,
    remaining_amount: "10000",
    paid_amount: 0,
    guest_count: 1,
    customer_id: null,
    tables: [],
    ...over,
  } as unknown as CustomerOrder;
}

const DEBT_FULL = "全額ツケ";
const DEBT_REMAINING = "残額をツケに";
const DEBT_LOOKUP = "ツケ照会";

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
});

// ===========================================================================
//  PaymentDialog — "全額ツケ" (Ghi nợ toàn bộ)
// ===========================================================================

function renderPaymentDialog(order: CustomerOrder) {
  const onCreateDebtPayment = vi.fn(() => Promise.resolve({ id: "pay-debt-1" }));
  render(
    <PaymentDialog
      open
      onOpenChange={vi.fn()}
      order={order}
      options={[cashOption, debtOption]}
      optionsLoading={false}
      policyRevision={1}
      outstanding={[]}
      outstandingLoading={false}
      onCreatePayment={vi.fn(() => Promise.resolve())}
      onCreateDebtPayment={onCreateDebtPayment}
      onDebtCharged={vi.fn()}
    />,
    { wrapper: Wrapper },
  );
  return { onCreateDebtPayment };
}

describe("PaymentDialog — debt CTA is reachable (issue #683)", () => {
  it("renders the debt button ENABLED for an order with a customer", () => {
    renderPaymentDialog(makeOrder({ customer_id: "cust-1" }));

    expect(screen.getByRole("button", { name: DEBT_FULL })).toBeEnabled();
  });

  it("charges the order's full remaining balance to the on_account method", async () => {
    const { onCreateDebtPayment } = renderPaymentDialog(
      makeOrder({ customer_id: "cust-1" }),
    );

    fireEvent.click(screen.getByRole("button", { name: DEBT_FULL }));

    await waitFor(() => expect(onCreateDebtPayment).toHaveBeenCalledTimes(1));
    expect(onCreateDebtPayment).toHaveBeenCalledWith(
      "ord-1",
      expect.objectContaining({
        payment_method_id: "pm-debt", // picked by type==="on_account", not the code string
        amount: 10000, // the full remaining_amount
      }),
    );
  });

  it("DISABLES the debt button for a walk-in (mirrors 422 customer_required_for_debt)", () => {
    renderPaymentDialog(makeOrder({ customer_id: null }));

    const btn = screen.getByRole("button", { name: DEBT_FULL });
    expect(btn).toBeDisabled();
    expect(btn).toHaveAttribute("title", expect.stringContaining("顧客未選択"));
  });

  it("keeps the primary cancel/confirm footer intact alongside the new CTA", () => {
    renderPaymentDialog(makeOrder({ customer_id: "cust-1" }));

    expect(screen.getByRole("button", { name: "支払い確定" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: DEBT_FULL })).toBeInTheDocument();
  });
});

// ===========================================================================
//  PaymentReceiptDialog — "残額をツケに" + "VAT請求書発行"
// ===========================================================================

function renderReceipt(
  over: {
    customerId?: string | null;
    remaining?: number;
  } = {},
) {
  const { customerId = "cust-1", remaining = 4000 } = over;
  const onCreateDebtPayment = vi.fn(() => Promise.resolve({ id: "pay-debt-2" }));
  render(
    <PaymentReceiptDialog
      open
      onOpenChange={vi.fn()}
      customer={{ name: "Tanaka", phone: "090" }}
      receipts={[
        { index: 1, title: "支払い", description: "ORD-1", amount: 6000 },
      ]}
      totalPaid={6000}
      tendered={6000}
      paidAt={new Date("2026-07-13T00:00:00Z")}
      orderId="ord-1"
      customerId={customerId}
      remaining={remaining}
      methods={[cash, debtMethod]}
      onCreateDebtPayment={onCreateDebtPayment}
      onDebtCharged={vi.fn()}
      onComplete={vi.fn()}
    />,
    { wrapper: Wrapper },
  );
  return { onCreateDebtPayment };
}

describe("PaymentReceiptDialog — post-payment CTAs are reachable (issue #683)", () => {
  it("offers to charge the REMAINING balance when the order is partially paid", async () => {
    const { onCreateDebtPayment } = renderReceipt({ remaining: 4000 });

    const btn = screen.getByRole("button", { name: DEBT_REMAINING });
    expect(btn).toBeEnabled();

    fireEvent.click(btn);

    await waitFor(() => expect(onCreateDebtPayment).toHaveBeenCalledTimes(1));
    // Charges only what's LEFT (4000), not the 6000 already collected.
    expect(onCreateDebtPayment).toHaveBeenCalledWith(
      "ord-1",
      expect.objectContaining({ payment_method_id: "pm-debt", amount: 4000 }),
    );
  });

  it("hides the remaining-debt CTA once the order is fully paid", () => {
    renderReceipt({ remaining: 0 });

    expect(
      screen.queryByRole("button", { name: DEBT_REMAINING }),
    ).not.toBeInTheDocument();
  });

  it("still renders the primary print/complete footer", () => {
    renderReceipt();

    expect(screen.getByRole("button", { name: "完了" })).toBeInTheDocument();
  });
});

// ===========================================================================
//  OrderCart — "ツケ照会" (Tra cứu nợ) trigger
//
//  The cart only owns the trigger; the dialog itself lives in the page,
//  because it needs the shop slug. So what's under test here is that the
//  button exists, is table-independent, and calls back.
// ===========================================================================

function renderCart(over: Partial<OrderCartProps> = {}) {
  const props: OrderCartProps = {
    order: makeOrder(),
    isLoading: false,
    errorMessage: null,
    onDismissError: vi.fn(),
    pendingUnmerge: null,
    onRetryUnmerge: vi.fn(),
    onDismissPendingUnmerge: vi.fn(),
    onAddItem: vi.fn(),
    onChangeQty: vi.fn(),
    onUpdateItemStatus: vi.fn(),
    onVoidItem: vi.fn(),
    onEditItemToppings: vi.fn(),
    // Contract thật: `checkout` trả TRUE khi đơn đã chuyển sang `checkout`.
    // Giả lập bằng `vi.fn()` trần (undefined) sẽ làm luồng một-chạm im lặng
    // không mở màn thu tiền — đúng nhánh lỗi mà bài test cần bắt được.
    onCheckout: vi.fn(() => Promise.resolve(true)),
    onApplyCoupon: vi.fn(() => Promise.resolve()),
    onReleaseCoupon: vi.fn(() => Promise.resolve()),
    onPay: vi.fn(),
    onSplitBill: vi.fn(),
    onVoid: vi.fn(),
    onAssignTable: vi.fn(),
    onEditGuestCount: vi.fn(),
    onChangeTable: vi.fn(),
    onMergeTable: vi.fn(),
    onUnmergeTable: vi.fn(),
    ...over,
  } as unknown as OrderCartProps;

  render(<OrderCart {...props} />, { wrapper: Wrapper });

  return { props };
}

// ===========================================================================
//  No auto-print — recording and printing are separate decisions
//
//  Both flows used to fire a slip the moment the write landed. The cashier
//  never asked for it, the customer may not want it, and every print is
//  recorded in print_history + the audit log. Paper must only come out on an
//  explicit click.
// ===========================================================================

describe("debt + invoice do NOT print on their own", () => {
  it("records the debt WITHOUT printing a slip", async () => {
    const { onCreateDebtPayment } = renderPaymentDialog(
      makeOrder({ customer_id: "cust-1" }),
    );

    fireEvent.click(screen.getByRole("button", { name: DEBT_FULL }));

    await waitFor(() => expect(onCreateDebtPayment).toHaveBeenCalledTimes(1));
    expect(printMock.printDebtSlip).not.toHaveBeenCalled();
  });

  it("prints the debt slip only once the cashier asks", async () => {
    render(
      <PrintResultDialog
        open
        onOpenChange={vi.fn()}
        target={{
          kind: "debt",
          orderId: "ord-1",
          paymentId: "pay-1",
          amount: 4000,
        }}
        detail="4.000"
        onDone={vi.fn()}
      />,
      { wrapper: Wrapper },
    );

    // Merely showing the result prints nothing.
    expect(printMock.printDebtSlip).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole("button", { name: /ツケ伝票を印刷/ }));

    await waitFor(() =>
      expect(printMock.printDebtSlip).toHaveBeenCalledWith(
        expect.objectContaining({ orderId: "ord-1", paymentId: "pay-1" }),
      ),
    );
  });

  it("lets the cashier walk away without printing at all", () => {
    const onDone = vi.fn();
    render(
      <PrintResultDialog
        open
        onOpenChange={vi.fn()}
        target={{
          kind: "debt",
          orderId: "ord-1",
          paymentId: "pay-1",
          amount: 4000,
        }}
        detail="4.000"
        onDone={onDone}
      />,
      { wrapper: Wrapper },
    );

    fireEvent.click(screen.getByRole("button", { name: "完了" }));

    expect(onDone).toHaveBeenCalledTimes(1);
    expect(printMock.printDebtSlip).not.toHaveBeenCalled();
  });
});

describe("OrderCart — the debt-lookup trigger has LEFT the cart (issue #683 → header)", () => {
  // #683 put the trigger here and pinned that it survives with no table
  // assigned. Both were right about the button being table-independent and
  // wrong about where it belongs: this cart early-returns when there is no
  // order, so a shop-wide question ("who owes us money") could only be asked
  // after creating an order — for a customer who might not even be the debtor.
  //
  // It now lives in PosHeader. What this file still owes is the guarantee that
  // it did not quietly come BACK: two triggers for one dialog is how a cashier
  // ends up believing the cart's copy is a different, order-scoped feature.
  it("no longer renders a debt-lookup button", () => {
    renderCart();

    expect(
      screen.queryByRole("button", { name: DEBT_LOOKUP }),
    ).not.toBeInTheDocument();
  });

  it("no longer renders one with no table assigned either", () => {
    renderCart({ order: makeOrder({ tables: [] }) });

    expect(
      screen.queryByRole("button", { name: DEBT_LOOKUP }),
    ).not.toBeInTheDocument();
  });
});

describe("OrderCart — totals stay collapsed until checkout (space-saving)", () => {
  const openOrderWithItem = () =>
    makeOrder({
      status: "open",
      subtotal: "6420",
      total_amount: 7415,
      items: [
        {
          id: "it-1",
          status: "served",
          quantity: 1,
          subtotal: "6420",
          toppings: [],
          product_sku: { name: null, product: { name: "Gỏi Cuốn" } },
        },
      ],
    } as unknown as Partial<CustomerOrder>);

  it("hides the breakdown by default; the total rides the 会計 button", () => {
    renderCart({ order: openOrderWithItem() });

    // Collapsed: no 小計 (subtotal) breakdown row is rendered yet…
    expect(screen.queryByText("小計")).toBeNull();

    // …but the payable total is visible on the checkout button itself.
    // (the test env formats as VND — "7.415 ₫" — so match the digit run.)
    const checkoutBtn = screen.getByRole("button", { name: /会計/ });
    expect(checkoutBtn).toHaveTextContent("7.415");
  });

  /*
   * MỘT CHẠM: 会計 giờ chốt đơn RỒI mở màn thu tiền, thay vì chỉ bung bảng
   * tách. Bảng tách lùi về sau nút "クーポンをお持ちですか？" — đường của đơn có
   * mã giảm giá, vốn là thiểu số.
   */
  it("会計 chốt đơn rồi mở màn thu tiền — một chạm, không còn ba", async () => {
    const { props } = renderCart({ order: openOrderWithItem() });

    fireEvent.click(screen.getByRole("button", { name: /会計/ }));

    await waitFor(() => expect(props.onCheckout).toHaveBeenCalledTimes(1));
    // Thứ tự là bắt buộc: mở màn thu tiền TRƯỚC khi đơn chuyển trạng thái sẽ
    // ăn 409 `Order not in checkout/paying status` ngay khi bấm Thu.
    await waitFor(() => expect(props.onPay).toHaveBeenCalledTimes(1));
  });

  it("checkout THẤT BẠI thì KHÔNG mở màn thu tiền", async () => {
    // Ca thật: món chưa phục vụ, ca thu ngân chưa mở, terminal khác đã chốt
    // trước. Mở màn tiền lúc này là đẩy thu ngân vào lỗi giữa lúc khách chờ.
    const onCheckout = vi.fn(() => Promise.resolve(false));
    const { props } = renderCart({ order: openOrderWithItem(), onCheckout });

    fireEvent.click(screen.getByRole("button", { name: /会計/ }));

    await waitFor(() => expect(onCheckout).toHaveBeenCalledTimes(1));
    expect(props.onPay).not.toHaveBeenCalled();
  });

  it("đường nhánh mã giảm giá mới bung bảng tách — và KHÔNG chốt đơn", () => {
    const { props } = renderCart({ order: openOrderWithItem() });

    // Bảng tách vẫn thu gọn cho tới khi thu ngân chủ động hỏi mã.
    expect(screen.queryByText("小計")).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: /クーポン/ }));

    expect(screen.getByText("小計")).toBeInTheDocument();
    expect(screen.getByText("合計")).toBeInTheDocument();
    // Mở form mã giảm giá KHÔNG được chạm vào trạng thái đơn — cửa `checkout`
    // là một chiều, không có route nào đưa đơn trở lại `open`.
    expect(props.onCheckout).not.toHaveBeenCalled();
    expect(props.onPay).not.toHaveBeenCalled();
  });
});
