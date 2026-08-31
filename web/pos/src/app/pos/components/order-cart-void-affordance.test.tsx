import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { OrderCart, type OrderCartProps } from "./order-cart";
import { setActiveCurrency } from "../lib/totals";
import type { CustomerOrder, OrderItemStatus } from "../types";

/**
 * #2925 — "không xoá được món khi đã chốt đơn".
 *
 * Luật (#1148 + plan-051) KHÔNG đổi ở đây: sửa/huỷ dòng là pending-only trừ khi
 * quán mở rộng ma trận, và đơn rời `open|confirmed` thì mọi dòng đóng lại. Cái
 * hỏng là giao diện: nút thùng rác được bọc `{canVoid && …}` nên khi bị chặn nó
 * BIẾN MẤT — nhân viên không có cách nào biết vì sao, và báo cáo "không xoá
 * được" chính là câu nói của một màn hình im lặng.
 *
 * File này ghim ba thứ, và thứ ba là thứ dễ trôi nhất:
 *   1. bị chặn ⇒ nút vẫn CÓ MẶT và `disabled` (không biến mất),
 *   2. lý do được NÓI RA, đúng cổng đã đóng — hai cổng, hai câu,
 *   3. cho phép thì vẫn cho phép: không có hành động nào bị siết thêm.
 */
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: {
    printPaymentReceipt: vi.fn(() => Promise.resolve()),
    printOrderBill: vi.fn(() => Promise.resolve({ status: "ok" })),
  },
}));

vi.mock("@/hooks/api/use-shop-payment-methods", () => ({
  useShopPaymentMethods: () => ({ data: [] }),
}));

vi.mock("@/hooks/api/use-shop-order-settings", () => ({
  useShopOrderSettings: () => ({ data: { data: { prices_include_tax: false } } }),
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

interface LineOverrides {
  status?: OrderItemStatus;
  refund_of_item_id?: string | null;
}

function buildOrder(status: string, line: LineOverrides = {}): CustomerOrder {
  return {
    id: "ord-1",
    order_code: "ORD-2026-2925",
    status,
    subtotal: "1000.00",
    discount_amount: "0.00",
    service_charge: "0.00",
    tax_amount: "0.00",
    total_amount: "1000.00",
    paid_amount: "0",
    remaining_amount: "1000",
    total_tip: "0",
    is_tax_included: false,
    tax_breakdown: [],
    guest_count: 1,
    items: [
      {
        id: "it-1",
        status: line.status ?? "pending",
        quantity: 1,
        unit_price: "1000",
        subtotal: "1000",
        toppings: [],
        refund_of_item_id: line.refund_of_item_id ?? null,
        product_sku: { name: null, product: { name: "Cà phê sữa" } },
      },
    ],
  } as unknown as CustomerOrder;
}

function mountCart(order: CustomerOrder, over: Partial<OrderCartProps> = {}) {
  const onVoidItem = vi.fn();
  render(
    <OrderCart
      {...({
        order,
        isLoading: false,
        errorMessage: null,
        onDismissError: vi.fn(),
        pendingUnmerge: null,
        onRetryUnmerge: vi.fn(),
        onDismissPendingUnmerge: vi.fn(),
        onAddItem: vi.fn(),
        onChangeQty: vi.fn(),
        onUpdateItemStatus: vi.fn(),
        onVoidItem,
        onEditItemToppings: vi.fn(),
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
      } as unknown as OrderCartProps)}
    />,
    { wrapper: Wrapper },
  );
  return { onVoidItem };
}

describe("#2925 — nút huỷ món bị chặn thì NÓI, không biến mất", () => {
  it("bếp đã nhận món: nút còn đó, khoá, và nói 'Món đã vào bếp'", () => {
    // Đơn vẫn `open` — chỉ riêng dòng món rời `pending` là đủ để cổng đóng.
    // Đây đúng ca người báo lỗi gặp: màn hình trông như bình thường, chỉ thiếu
    // mất cái nút.
    mountCart(buildOrder("open", { status: "served" }), {
      voidableStatuses: ["pending"],
    });

    const btn = screen.getByRole("button", { name: /Món đã vào bếp/ });
    expect(btn).toBeDisabled();
    expect(screen.getByText("Món đã vào bếp")).toBeInTheDocument();
  });

  it("đơn đã chốt: lý do là 'Đơn đã chốt', KHÔNG phải lý do bếp", () => {
    // Dòng vẫn `pending` mà đơn đã sang `checkout`: cổng ĐƠN đóng. Nói "món đã
    // vào bếp" ở đây là chỉ sai chỗ — nhân viên sẽ đi hỏi bếp một món bếp chưa
    // hề nhận.
    mountCart(buildOrder("checkout", { status: "pending" }), {
      voidableStatuses: ["pending"],
    });

    const btn = screen.getByRole("button", { name: /Đơn đã chốt/ });
    expect(btn).toBeDisabled();
    expect(screen.queryByText("Món đã vào bếp")).not.toBeInTheDocument();
  });

  it("cổng ĐƠN nói trước khi cả hai cổng cùng đóng", () => {
    mountCart(buildOrder("closed", { status: "served" }), {
      voidableStatuses: ["pending"],
    });

    expect(screen.getByRole("button", { name: /Đơn đã chốt/ })).toBeDisabled();
    expect(screen.queryByText("Món đã vào bếp")).not.toBeInTheDocument();
  });
});

describe("#2925 — luật #1148 không bị nới ra một ly", () => {
  it("dòng pending của đơn open vẫn huỷ được bằng một cú chạm", () => {
    const { onVoidItem } = mountCart(buildOrder("open"), {
      voidableStatuses: ["pending"],
    });

    const btn = screen.getByRole("button", { name: "Huỷ món" });
    expect(btn).toBeEnabled();
    fireEvent.click(btn);
    expect(onVoidItem).toHaveBeenCalledWith("it-1", "Cà phê sữa");
  });

  it("quán mở rộng ma trận thì món đã phục vụ vẫn huỷ được", () => {
    // plan-051: ma trận theo quán. Bản sửa giao diện không được đụng vào nó.
    const { onVoidItem } = mountCart(buildOrder("open", { status: "served" }), {
      voidableStatuses: ["pending", "preparing", "ready", "served"],
    });

    fireEvent.click(screen.getByRole("button", { name: "Huỷ món" }));
    expect(onVoidItem).toHaveBeenCalledTimes(1);
  });

  it("bấm vào nút đã khoá KHÔNG gọi huỷ", () => {
    const { onVoidItem } = mountCart(buildOrder("open", { status: "served" }), {
      voidableStatuses: ["pending"],
    });

    fireEvent.click(screen.getByRole("button", { name: /Món đã vào bếp/ }));
    expect(onVoidItem).not.toHaveBeenCalled();
  });

  it("dòng HOÀN không có nút nào cả — #2193 giữ nguyên", () => {
    // Một nút khoá VĨNH VIỄN chỉ là nhiễu: dòng hoàn không bao giờ huỷ được,
    // và qty âm đã nói nó là bút toán đảo.
    mountCart(buildOrder("open", { refund_of_item_id: "it-0" }), {
      voidableStatuses: ["pending"],
    });

    expect(screen.queryByRole("button", { name: /Huỷ món/ })).toBeNull();
    expect(screen.queryByText("Món đã vào bếp")).not.toBeInTheDocument();
  });
});
