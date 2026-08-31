import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { markApiOutcome, resetNetworkStatus } from "@/lib/network-status";
import { PaymentDialog } from "./payment-dialog";
import type { CustomerOrder, EffectivePaymentOption } from "../types";

// #1156 — the brand sub-choice reads the effective tender vocabulary +
// registered terminals through tillService; mock the network edge so the
// dialog tests stay hermetic. Only exercised when a shopSlug is passed.
vi.mock("@/services/till-service", () => ({
  tillService: {
    tenderTypes: vi.fn(() =>
      Promise.resolve({
        data: [
          { id: "t-cash", tender_key: "cash", name: "現金", category: "cash", parent_tender_key: null, currency_code: "JPY", payment_method_code: "cash", is_expected_anchor: true, requires_terminal_total: false, sort_order: 0 },
          { id: "t-credit", tender_key: "credit", name: "クレジット", category: "card", parent_tender_key: null, currency_code: "JPY", payment_method_code: "card", is_expected_anchor: true, requires_terminal_total: true, sort_order: 1 },
          { id: "t-paypay", tender_key: "paypay", name: "PayPay", category: "qr", parent_tender_key: null, currency_code: "JPY", payment_method_code: null, is_expected_anchor: false, requires_terminal_total: true, sort_order: 2 },
          { id: "t-id", tender_key: "id", name: "iD", category: "emoney", parent_tender_key: null, currency_code: "JPY", payment_method_code: null, is_expected_anchor: false, requires_terminal_total: true, sort_order: 3 },
        ],
      }),
    ),
    tenderCategories: vi.fn(() =>
      Promise.resolve({
        data: [
          { id: "c-cash", key: "cash", name: "現金", sort_order: 0, is_system: true },
          { id: "c-card", key: "card", name: "クレジット", sort_order: 1, is_system: true },
          { id: "c-qr", key: "qr", name: "QR決済", sort_order: 2, is_system: true },
          { id: "c-emoney", key: "emoney", name: "電子マネー", sort_order: 3, is_system: true },
        ],
      }),
    ),
    paymentTerminals: vi.fn(() => Promise.resolve({ data: [] })),
  },
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

function makeOption(
  over: Partial<EffectivePaymentOption>,
): EffectivePaymentOption {
  return {
    id: "opt-default",
    display_name: "Default",
    provider: "internal",
    rail: "cash",
    method_type: "cash",
    effective: true,
    source: "shop",
    reason: "",
    error_code: null,
    connection_id: "conn-1",
    connection_option_id: null,
    shop_option_id: "shop-opt-1",
    shop_preference: "inherit",
    device_preference: "inherit",
    legacy_payment_method_id: "pm-default",
    legacy_payment_method_code: "cash",
    client: {
      requires_tendered: false,
      immediate_settlement: true,
      supports_pos_checkout: true,
    },
    ...over,
  };
}

const cash = makeOption({
  id: "opt-cash",
  display_name: "Cash",
  legacy_payment_method_id: "pm-cash",
  legacy_payment_method_code: "cash",
  client: {
    requires_tendered: true,
    immediate_settlement: true,
    supports_pos_checkout: true,
  },
});

const cardTerminal = makeOption({
  id: "opt-card-terminal",
  display_name: "Thiết bị thanh toán",
  method_type: "card_terminal",
  legacy_payment_method_id: "pm-card-terminal",
  legacy_payment_method_code: "card_terminal",
  client: {
    requires_tendered: false,
    immediate_settlement: true,
    supports_pos_checkout: true,
  },
});

/** Non-immediate settlement → filtered out by checkoutCapableOptions. */
const transfer = makeOption({
  id: "opt-transfer",
  display_name: "Transfer",
  rail: "bank_transfer",
  method_type: "transfer",
  legacy_payment_method_id: "pm-transfer",
  legacy_payment_method_code: "transfer",
  client: {
    requires_tendered: false,
    immediate_settlement: false,
    supports_pos_checkout: false,
  },
});

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

interface RenderOpts {
  order: CustomerOrder;
  outstanding?: CustomerOrder[];
  options?: EffectivePaymentOption[];
  policyRevision?: number;
  onCreatePayment?: ReturnType<typeof vi.fn>;
  /** #1156 — enables the tender-vocabulary queries behind the brand picker. */
  shopSlug?: string;
  /** The effective-options query failed (connection fault, not a config gap). */
  optionsError?: unknown;
  onRetryOptions?: ReturnType<typeof vi.fn>;
}

function renderDialog({
  order,
  outstanding = [],
  options = [cash],
  policyRevision = 1,
  onCreatePayment = vi.fn(() => Promise.resolve()),
  shopSlug,
  optionsError,
  onRetryOptions,
}: RenderOpts) {
  render(
    <PaymentDialog
      open
      onOpenChange={vi.fn()}
      shopSlug={shopSlug}
      order={order}
      options={options}
      optionsLoading={false}
      optionsError={optionsError}
      onRetryOptions={onRetryOptions}
      policyRevision={policyRevision}
      outstanding={outstanding}
      outstandingLoading={false}
      onCreatePayment={onCreatePayment}
    />,
    { wrapper: Wrapper },
  );
  return { onCreatePayment };
}

function pickCashAndTender(amount: number) {
  fireEvent.click(screen.getByRole("button", { name: /Cash/ }));
  const input = screen.getByRole("spinbutton");
  fireEvent.change(input, { target: { value: String(amount) } });
}

function confirmButton(): HTMLButtonElement {
  return screen.getByRole("button", { name: "支払い確定" }) as HTMLButtonElement;
}

beforeEach(() => {
  localStorage.clear();
  vi.clearAllMocks();
});

describe("PaymentDialog — walk-in under-tender is blocked", () => {
  it("shows the walk-in banner, hides the amber shortfall, and disables confirm", () => {
    renderDialog({ order: makeOrder({ customer_id: null }) });
    pickCashAndTender(6000);

    expect(screen.getByText(/全額お支払い/)).toBeInTheDocument();
    expect(screen.queryByText(/今回の不足分/)).not.toBeInTheDocument();
    expect(confirmButton()).toBeDisabled();
    expect(screen.queryByText("未払いがあるお客様")).not.toBeInTheDocument();
  });
});

describe("PaymentDialog — walk-in paid in full", () => {
  it("enables confirm and fires one exact-amount payment WITHOUT gateway identity (internal tender)", async () => {
    const { onCreatePayment } = renderDialog({
      order: makeOrder({ customer_id: null }),
    });
    pickCashAndTender(10000);

    const btn = confirmButton();
    expect(btn).toBeEnabled();

    fireEvent.click(btn);

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    // Plan-048 T1.2 — the cash fixture is an INTERNAL catalog tender
    // (provider "internal"): it records via recordTender with NO gateway
    // identity; echoing the catalog option id trips the fail-closed policy
    // validator (PAYMENT_OPTION_DISABLED) on ownership-unresolved branches.
    expect(onCreatePayment).toHaveBeenCalledWith(
      "ord-1",
      expect.objectContaining({
        payment_method_id: "pm-cash",
        amount: 10000,
        tendered_amount: 10000,
      }),
    );
    const [, body] = onCreatePayment.mock.calls[0];
    expect(body.gateway_option_id).toBeUndefined();
    expect(body.gateway_connection_id).toBeUndefined();
    expect(body.policy_revision).toBeUndefined();
  });

  it("keeps gateway identity for a NON-internal (gateway-backed) option", async () => {
    const gatewayCash = makeOption({
      id: "opt-gw",
      display_name: "Gateway Pay",
      provider: "stripe",
      source: "shop",
      rail: "wallet",
      method_type: "wallet",
      legacy_payment_method_id: "pm-gw",
      legacy_payment_method_code: "e_wallet",
      client: {
        requires_tendered: false,
        immediate_settlement: true,
        supports_pos_checkout: true,
      },
    });
    const { onCreatePayment } = renderDialog({
      order: makeOrder({ customer_id: null }),
      options: [gatewayCash],
    });
    fireEvent.click(screen.getByRole("button", { name: /Gateway Pay/ }));
    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    expect(onCreatePayment).toHaveBeenCalledWith(
      "ord-1",
      expect.objectContaining({
        payment_method_id: "pm-gw",
        gateway_option_id: "opt-gw",
        gateway_connection_id: "conn-1",
        policy_revision: 1,
      }),
    );
  });
});

describe("PaymentDialog — registered customer may under-tender (debt rolls over)", () => {
  it("shows the amber shortfall, hides the walk-in banner, and fires the tendered amount", async () => {
    const { onCreatePayment } = renderDialog({
      order: makeOrder({ customer_id: "cust-001" }),
    });
    pickCashAndTender(6000);

    expect(screen.getByText(/今回の不足分/)).toBeInTheDocument();
    expect(screen.queryByText(/全額お支払い/)).not.toBeInTheDocument();

    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    expect(onCreatePayment).toHaveBeenCalledWith(
      "ord-1",
      expect.objectContaining({ amount: 6000, tendered_amount: 6000 }),
    );
  });
});

describe("PaymentDialog — outstanding debt settled oldest-first within budget", () => {
  it("pays the old debt fully, then applies the remainder to the current order", async () => {
    const debt = makeOrder({
      id: "debt-1",
      order_code: "ORD-D1",
      total_amount: 2000,
      remaining_amount: "2000",
    });
    const { onCreatePayment } = renderDialog({
      order: makeOrder({ customer_id: "cust-001" }),
      outstanding: [debt],
    });

    pickCashAndTender(5000);
    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(2));
    expect(onCreatePayment.mock.calls[0][0]).toBe("debt-1");
    expect(onCreatePayment.mock.calls[0][1]).toMatchObject({ amount: 2000 });
    expect(onCreatePayment.mock.calls[1][0]).toBe("ord-1");
    expect(onCreatePayment.mock.calls[1][1]).toMatchObject({ amount: 3000 });
  });
});

describe("PaymentDialog — card terminal needs no amount entry", () => {
  const options = [cash, cardTerminal];

  it("renders no keypad and enables confirm the moment the tile is picked", () => {
    renderDialog({ order: makeOrder({ customer_id: null }), options });

    pickCashAndTender(20000);
    expect(screen.getByRole("spinbutton")).toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: /Thiết bị thanh toán/ }));
    expect(screen.queryByRole("spinbutton")).not.toBeInTheDocument();
    expect(confirmButton()).toBeEnabled();
  });

  it("posts the full total with no tendered_amount", async () => {
    const { onCreatePayment } = renderDialog({
      order: makeOrder({ customer_id: null }),
      options,
    });

    fireEvent.click(screen.getByRole("button", { name: /Thiết bị thanh toán/ }));
    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const [, body] = onCreatePayment.mock.calls[0];
    expect(body).toMatchObject({
      payment_method_id: "pm-card-terminal",
      amount: 10000,
    });
    // Internal catalog tender → no gateway identity (plan-048 T1.2).
    expect(body.gateway_option_id).toBeUndefined();
    expect(body.tendered_amount).toBeUndefined();
  });
});

describe("PaymentDialog — #1156 terminal brand sub-choice → tender_key", () => {
  const options = [cash, cardTerminal];

  it("picking 決済端末 reveals grouped brand chips from the effective vocabulary", async () => {
    renderDialog({
      order: makeOrder({ customer_id: null }),
      options,
      shopSlug: "ningyocho",
    });

    fireEvent.click(screen.getByRole("button", { name: /Thiết bị thanh toán/ }));

    await waitFor(() =>
      expect(screen.getByRole("button", { name: "PayPay" })).toBeInTheDocument(),
    );
    // Groups: card / qr / emoney headers; cash never offered.
    expect(screen.getByText("QR決済")).toBeInTheDocument();
    expect(screen.getByText("電子マネー")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "クレジット" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "現金" })).not.toBeInTheDocument();
    // Skip path is the default selection.
    expect(screen.getByRole("button", { name: "あとで / 不明" })).toBeInTheDocument();
    // Confirm is live without any brand chosen — the choice never blocks.
    expect(confirmButton()).toBeEnabled();
  });

  it("choosing a brand stamps tender_key onto the payment body", async () => {
    const { onCreatePayment } = renderDialog({
      order: makeOrder({ customer_id: null }),
      options,
      shopSlug: "ningyocho",
    });

    fireEvent.click(screen.getByRole("button", { name: /Thiết bị thanh toán/ }));
    await waitFor(() =>
      expect(screen.getByRole("button", { name: "PayPay" })).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole("button", { name: "PayPay" }));
    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const [, body] = onCreatePayment.mock.calls[0];
    expect(body).toMatchObject({
      payment_method_id: "pm-card-terminal",
      tender_key: "paypay",
    });
  });

  it("skip path sends NO tender_key (payment never blocked on the choice)", async () => {
    const { onCreatePayment } = renderDialog({
      order: makeOrder({ customer_id: null }),
      options,
      shopSlug: "ningyocho",
    });

    fireEvent.click(screen.getByRole("button", { name: /Thiết bị thanh toán/ }));
    await waitFor(() =>
      expect(screen.getByRole("button", { name: "PayPay" })).toBeInTheDocument(),
    );
    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const [, body] = onCreatePayment.mock.calls[0];
    expect(body.tender_key).toBeUndefined();
  });

  it("switching to a non-terminal method clears the brand and omits tender_key", async () => {
    const { onCreatePayment } = renderDialog({
      order: makeOrder({ customer_id: null }),
      options,
      shopSlug: "ningyocho",
    });

    fireEvent.click(screen.getByRole("button", { name: /Thiết bị thanh toán/ }));
    await waitFor(() =>
      expect(screen.getByRole("button", { name: "PayPay" })).toBeInTheDocument(),
    );
    fireEvent.click(screen.getByRole("button", { name: "PayPay" }));

    // Back to cash — the brand block hides and the key must not leak through.
    pickCashAndTender(10000);
    expect(screen.queryByRole("button", { name: "PayPay" })).not.toBeInTheDocument();
    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const [, body] = onCreatePayment.mock.calls[0];
    expect(body.payment_method_id).toBe("pm-cash");
    expect(body.tender_key).toBeUndefined();
  });

  it("brand chips never render for a shop without slug context (queries disabled)", () => {
    renderDialog({ order: makeOrder({ customer_id: null }), options });
    fireEvent.click(screen.getByRole("button", { name: /Thiết bị thanh toán/ }));
    expect(screen.queryByRole("button", { name: "PayPay" })).not.toBeInTheDocument();
    expect(confirmButton()).toBeEnabled();
  });
});

describe("PaymentDialog — effective options resolver (F6/F8)", () => {
  it("renders only checkout-capable options, not unsupported rails", () => {
    renderDialog({
      order: makeOrder(),
      options: [cash, cardTerminal, transfer],
    });

    expect(screen.getByRole("button", { name: /Cash/ })).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: /Thiết bị thanh toán/ }),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: /Transfer/ })).not.toBeInTheDocument();
  });

  it("blocks checkout with a manager message when no checkout-capable options", () => {
    renderDialog({
      order: makeOrder(),
      options: [transfer],
    });

    expect(screen.getByText(/決済方法が設定されていません/)).toBeInTheDocument();
    expect(confirmButton()).toBeDisabled();
  });
});

describe("PaymentDialog — cash overpay: change math + #538 amount cap", () => {
  it("shows the change and never fires more than the order's remaining", async () => {
    const order = makeOrder({ total_amount: 10000, remaining_amount: "10000" });
    const { onCreatePayment } = renderDialog({ order });

    pickCashAndTender(15000);

    // Cashier-facing change: 15,000 handed over − 10,000 owed = 5,000.
    expect(screen.getByText("お釣り:")).toBeInTheDocument();
    expect(screen.getByText(/5[,.]?000/)).toBeInTheDocument();

    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const [, payload] = onCreatePayment.mock.calls[0];
    // #538 — the recorded amount is capped at what is owed, regardless of
    // how much cash physically changed hands.
    expect(payload.amount).toBe(10000);
    // ...but the TENDER is what the customer actually handed over, so the
    // server can derive change = 15,000 − 10,000. Asserting only `amount`
    // here is what let the tendered bug ship: the on-screen "お釣り" was
    // right while the persisted row said tendered 10,000 / change 0.
    expect(payload.tendered_amount).toBe(15000);
  });

  it("REGRESSION: an overpaid cash sale persists the cash handed over, not the bill", async () => {
    // The reported case, to the yen: ¥1,793 bill, cashier typed ¥2,000.
    // The printed slip read "tendered 1,793 / change 0" because the dialog
    // sent the row's own amount as the tender.
    const order = makeOrder({ total_amount: 1793, remaining_amount: "1793" });
    const { onCreatePayment } = renderDialog({ order });

    pickCashAndTender(2000);

    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const [, payload] = onCreatePayment.mock.calls[0];
    expect(payload.amount).toBe(1793);
    expect(payload.tendered_amount).toBe(2000);
    // Server derives change = tendered − amount − tip; ¥207 must be
    // reachable from the payload alone.
    expect(payload.tendered_amount - payload.amount).toBe(207);
  });
});

describe("PaymentDialog — cash overpay across a debt sequence", () => {
  it("gives the change back ONCE, on the current order's row", async () => {
    // Cash settles two old debts then the current order. The server derives
    // change per ROW, so putting the ¥5,000 handover on all three rows would
    // report the change three times and wreck the shift's cash count.
    const order = makeOrder({
      id: "ord-cur",
      total_amount: 1500,
      remaining_amount: "1500",
      customer_id: "cus-1",
    });
    const outstanding = [
      makeOrder({ id: "debt-1", order_code: "D-1", remaining_amount: "1000" }),
      makeOrder({ id: "debt-2", order_code: "D-2", remaining_amount: "2000" }),
    ];
    const { onCreatePayment } = renderDialog({ order, outstanding });

    pickCashAndTender(5000);

    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(3));

    const calls = onCreatePayment.mock.calls.map(
      ([orderId, body]: [string, { amount: number; tendered_amount: number }]) => ({
        orderId,
        amount: body.amount,
        tendered: body.tendered_amount,
      }),
    );

    // Debts consume exactly what they owe → no change on those rows.
    expect(calls[0]).toEqual({ orderId: "debt-1", amount: 1000, tendered: 1000 });
    expect(calls[1]).toEqual({ orderId: "debt-2", amount: 2000, tendered: 2000 });
    // The surplus rides the current order — the slip the customer gets.
    expect(calls[2]).toEqual({ orderId: "ord-cur", amount: 1500, tendered: 2000 });

    // The whole handover is accounted for exactly once.
    const change = calls.reduce((sum, c) => sum + (c.tendered - c.amount), 0);
    const charged = calls.reduce((sum, c) => sum + c.amount, 0);
    expect(change).toBe(500);
    expect(charged + change).toBe(5000);
  });
});

describe("PaymentDialog — quick-denomination tiles accumulate the tendered total", () => {
  it("adds each tap to the tendered input (VND fallback tiles)", () => {
    const order = makeOrder({ total_amount: 120000, remaining_amount: "120000" });
    renderDialog({ order });

    fireEvent.click(screen.getByRole("button", { name: /Cash/ }));
    // No currencyCode prop → VND fallback tiles (50k / 100k / 200k / 500k).
    fireEvent.click(screen.getByRole("button", { name: "50k" }));
    fireEvent.click(screen.getByRole("button", { name: "50k" }));
    fireEvent.click(screen.getByRole("button", { name: "100k" }));

    const input = screen.getByRole("spinbutton") as HTMLInputElement;
    expect(input.value).toBe("200000");
  });
});

describe("PaymentDialog — exact-amount shortcut", () => {
  it("locks the input, zeroes the change, and fires exactly the grand total", async () => {
    const order = makeOrder({ total_amount: 10000, remaining_amount: "10000" });
    const { onCreatePayment } = renderDialog({ order });

    fireEvent.click(screen.getByRole("button", { name: /Cash/ }));
    fireEvent.click(screen.getByRole("checkbox"));

    const input = screen.getByRole("spinbutton") as HTMLInputElement;
    expect(input).toBeDisabled();
    expect(input.value).toBe("10000");
    // Exact tender → no change line.
    expect(screen.queryByText("お釣り:")).not.toBeInTheDocument();

    fireEvent.click(confirmButton());

    await waitFor(() => expect(onCreatePayment).toHaveBeenCalledTimes(1));
    const [, payload] = onCreatePayment.mock.calls[0];
    expect(payload.amount).toBe(10000);
    expect(payload.tendered_amount).toBe(10000);
  });
});

describe("PaymentDialog — a failed options fetch is not a missing config", () => {
  const ERROR_TITLE = "決済方法を読み込めませんでした";
  const EMPTY_TITLE = "レジで利用できる決済方法が設定されていません";

  it("renders the connection-fault card (with retry), NOT the «not configured» card", () => {
    // The shipped bug: the embedded POS talked to a host that never issued its
    // token, every /pos/* call failed, and this dialog told the cashier the shop
    // had no payment policy — sending them to a manager over a routing fault.
    const onRetryOptions = vi.fn();
    renderDialog({
      order: makeOrder(),
      options: [],
      optionsError: new Error("Failed to fetch"),
      onRetryOptions,
    });

    expect(screen.getByText(ERROR_TITLE)).toBeInTheDocument();
    expect(screen.queryByText(EMPTY_TITLE)).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole("button", { name: "再試行" }));
    expect(onRetryOptions).toHaveBeenCalledTimes(1);
  });

  it("still renders the «not configured» card when the fetch SUCCEEDED and the policy is empty", () => {
    renderDialog({ order: makeOrder(), options: [] });

    expect(screen.getByText(EMPTY_TITLE)).toBeInTheDocument();
    expect(screen.queryByText(ERROR_TITLE)).not.toBeInTheDocument();
  });

  it("options that are not checkout-capable read as «not configured», not as an error", () => {
    renderDialog({ order: makeOrder(), options: [transfer] });

    expect(screen.getByText(EMPTY_TITLE)).toBeInTheDocument();
    expect(screen.queryByText(ERROR_TITLE)).not.toBeInTheDocument();
  });

  it("keeps the method grid when a background refetch fails but options are cached", () => {
    renderDialog({
      order: makeOrder(),
      options: [cash, cardTerminal],
      optionsError: new Error("network"),
    });

    expect(screen.getByRole("button", { name: /Cash/ })).toBeInTheDocument();
    expect(screen.queryByText(ERROR_TITLE)).not.toBeInTheDocument();
    expect(screen.queryByText(EMPTY_TITLE)).not.toBeInTheDocument();
  });
});

/*
 * #1501 — thanh toán là hành động TIỀN: mất mạng thì khoá hẳn, KHÔNG xếp
 * hàng chờ. Tương lai có ai thêm hàng đợi cho nút này thì đọc lại
 * `src/hooks/use-network-required.ts` trước.
 */
describe("PaymentDialog — mất kết nối thì khoá nút xác nhận (#1501)", () => {
  beforeEach(() => {
    resetNetworkStatus();
  });

  it("đủ tiền nhưng offline ⇒ nút xác nhận bị khoá kèm lý do", () => {
    markApiOutcome("network-error");
    markApiOutcome("network-error");

    renderDialog({ order: makeOrder({ customer_id: null }) });
    pickCashAndTender(10_000);

    expect(confirmButton()).toBeDisabled();
    expect(confirmButton()).toHaveAttribute("title");
  });

  it("có mạng thì vẫn bấm được như cũ", () => {
    renderDialog({ order: makeOrder({ customer_id: null }) });
    pickCashAndTender(10_000);

    expect(confirmButton()).toBeEnabled();
    expect(confirmButton()).not.toHaveAttribute("title");
  });
});
