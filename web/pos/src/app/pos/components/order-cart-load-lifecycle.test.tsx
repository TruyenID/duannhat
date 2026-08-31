import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { OrderCart, type OrderCartProps } from "./order-cart";
import { setActiveCurrency } from "../lib/totals";
import type { CustomerOrder } from "../types";

/**
 * #2746 — React error #310 ("Rendered more hooks than during the previous
 * render") crashed the whole OrderCart when an order finished loading.
 *
 * Production shape: first paint is `order=undefined` / `isLoading=true`
 * (skeleton), then the query resolves and the same component instance
 * renders the cart. The reopen/void `useState` + tab-switch `useEffect`
 * used to sit AFTER those early returns, so the second paint called three
 * extra hooks and React aborted the tree.
 *
 * pos-web has no Playwright runner; this file is the e2e for that crash:
 * the real OrderCart, the real loading→loaded (and empty→loaded, tab
 * switch) sequence, asserted on the screen the cashier sees.
 */
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: {
    enabled: false,
    printOrderBill: vi.fn(() => Promise.resolve({ status: "ok" })),
    printPaymentReceipt: vi.fn(() => Promise.resolve()),
  },
}));

const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false } },
});

function Wrapper({ children }: { children: ReactNode }) {
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
  setActiveCurrency("JPY");
});

const orderA = {
  id: "ord-a",
  order_code: "ORD-2026-310-A",
  status: "open",
  subtotal: "1150.00",
  discount_amount: "0.00",
  service_charge: "0.00",
  tax_amount: "0.00",
  total_amount: "1150.00",
  paid_amount: "0",
  remaining_amount: "1150",
  total_tip: "0",
  is_tax_included: true,
  tax_breakdown: [],
  guest_count: 1,
  items: [
    {
      id: "it-1",
      status: "served",
      quantity: 1,
      unit_price: "1150",
      subtotal: "1150",
      toppings: [],
      product_sku: { name: null, product: { name: "Phở bò" } },
    },
  ],
} as unknown as CustomerOrder;

const orderB = {
  ...orderA,
  id: "ord-b",
  order_code: "ORD-2026-310-B",
  status: "checkout",
} as unknown as CustomerOrder;

const checkoutA = {
  ...orderA,
  status: "checkout",
} as unknown as CustomerOrder;

function cartRoot(): HTMLElement {
  const el = document.querySelector('[data-slot="order-cart"]');
  if (!el) throw new Error("OrderCart root [data-slot=order-cart] missing");
  return el as HTMLElement;
}

function stubs(over: Partial<OrderCartProps> = {}): OrderCartProps {
  return {
    order: undefined,
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
    onCheckout: vi.fn(() => Promise.resolve(true)),
    onApplyCoupon: vi.fn(() => Promise.resolve()),
    onReleaseCoupon: vi.fn(() => Promise.resolve()),
    onPay: vi.fn(),
    onSplitBill: vi.fn(),
    onVoid: vi.fn(() => Promise.resolve()),
    onReopen: vi.fn(() => Promise.resolve()),
    onAssignTable: vi.fn(),
    onEditGuestCount: vi.fn(),
    onChangeTable: vi.fn(),
    onMergeTable: vi.fn(),
    onUnmergeTable: vi.fn(),
    pricesIncludeTax: true,
    ...over,
  } as OrderCartProps;
}

describe("OrderCart load lifecycle — React #310 (#2746)", () => {
  it("loading → order arrives on the SAME instance does not crash", () => {
    const { rerender } = render(
      <OrderCart {...stubs({ order: undefined, isLoading: true })} />,
      { wrapper: Wrapper },
    );

    expect(cartRoot()).toBeInTheDocument();
    expect(screen.queryByText("Chưa có đơn")).toBeNull();

    expect(() =>
      rerender(<OrderCart {...stubs({ order: orderA, isLoading: false })} />),
    ).not.toThrow();

    expect(screen.getByText("ORD-2026-310-A")).toBeInTheDocument();
    expect(screen.getByText("Phở bò")).toBeInTheDocument();
  });

  it("empty cart → order arrives on the SAME instance does not crash", () => {
    const { rerender } = render(
      <OrderCart {...stubs({ order: undefined, isLoading: false })} />,
      { wrapper: Wrapper },
    );

    expect(screen.getByText("Chưa có đơn")).toBeInTheDocument();

    expect(() =>
      rerender(<OrderCart {...stubs({ order: orderA, isLoading: false })} />),
    ).not.toThrow();

    expect(screen.queryByText("Chưa có đơn")).toBeNull();
    expect(screen.getByText("ORD-2026-310-A")).toBeInTheDocument();
  });

  it("loaded → loading → loaded again (refetch / tab flicker) stays mounted", () => {
    const { rerender } = render(
      <OrderCart {...stubs({ order: orderA, isLoading: false })} />,
      { wrapper: Wrapper },
    );
    expect(screen.getByText("ORD-2026-310-A")).toBeInTheDocument();

    expect(() => {
      rerender(<OrderCart {...stubs({ order: undefined, isLoading: true })} />);
      rerender(<OrderCart {...stubs({ order: orderA, isLoading: false })} />);
    }).not.toThrow();

    expect(screen.getByText("ORD-2026-310-A")).toBeInTheDocument();
  });
});

describe("OrderCart tab switch closes dialogs (#2479 / #2746)", () => {
  it("đổi tab lúc hộp thoại Huỷ đang mở — đóng dialog, không áp lý do lên đơn mới", async () => {
    const { rerender } = render(
      <OrderCart {...stubs({ order: checkoutA, isLoading: false })} />,
      { wrapper: Wrapper },
    );

    fireEvent.click(screen.getByRole("button", { name: "Huỷ đơn" }));
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toHaveTextContent("ORD-2026-310-A");

    rerender(<OrderCart {...stubs({ order: orderB, isLoading: false })} />);

    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    expect(screen.getByText("ORD-2026-310-B")).toBeInTheDocument();
  });

  it("đổi tab lúc hộp thoại Mở lại đang mở — đóng dialog", async () => {
    const { rerender } = render(
      <OrderCart {...stubs({ order: checkoutA, isLoading: false })} />,
      { wrapper: Wrapper },
    );

    fireEvent.click(screen.getByRole("button", { name: "Mở lại đơn" }));
    expect(await screen.findByRole("dialog")).toBeInTheDocument();
    expect(screen.getByRole("dialog")).toHaveTextContent("ORD-2026-310-A");

    rerender(<OrderCart {...stubs({ order: orderB, isLoading: false })} />);

    await waitFor(() => expect(screen.queryByRole("dialog")).toBeNull());
    expect(screen.getByText("ORD-2026-310-B")).toBeInTheDocument();
  });

  it("đơn checkout hiện CẢ hai nút Mở lại và Huỷ — cạnh nhau", () => {
    render(<OrderCart {...stubs({ order: checkoutA, isLoading: false })} />, {
      wrapper: Wrapper,
    });

    expect(
      screen.getByRole("button", { name: "Mở lại đơn" }),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Huỷ đơn" })).toBeInTheDocument();
  });

  it("đơn open không hiện Mở lại — paying cũng không (tiền đã vào)", () => {
    const { rerender } = render(
      <OrderCart {...stubs({ order: orderA, isLoading: false })} />,
      { wrapper: Wrapper },
    );
    expect(screen.queryByRole("button", { name: "Mở lại đơn" })).toBeNull();
    expect(screen.getByRole("button", { name: "Huỷ đơn" })).toBeInTheDocument();

    rerender(
      <OrderCart
        {...stubs({
          order: { ...orderA, status: "paying" } as CustomerOrder,
          isLoading: false,
        })}
      />,
    );
    expect(screen.queryByRole("button", { name: "Mở lại đơn" })).toBeNull();
  });
});
