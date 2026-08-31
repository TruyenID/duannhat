import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { setActiveCurrency } from "../lib/totals";
import type { CustomerOrder } from "../types";
import { TakeawayOrdersView } from "./takeaway-orders-view";

// Force vi locale so label assertions are deterministic (the vitest env has no
// VITE_DEFAULT_LOCALE, so AppProvider would otherwise default to ja).
beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
  setActiveCurrency("VND");
});

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

function makeOrder(over: Partial<CustomerOrder>): CustomerOrder {
  return {
    id: "o1",
    order_code: "ORD-1001",
    order_type: "takeaway",
    status: "pending",
    subtotal: 0,
    discount_amount: 0,
    service_charge: 0,
    tax_amount: 0,
    total_amount: 50000,
    paid_amount: 0,
    total_tip: 0,
    remaining_amount: "50000",
    opened_at: null,
    checkout_at: null,
    closed_at: null,
    voided_at: null,
    void_reason: null,
    guest_count: null,
    note: null,
    stock_out_transaction_id: null,
    created_by_id: null,
    customer_account_id: null,
    customer_id: null,
    branch_id: "b1",
    brand_id: "br1",
    organization_id: "org1",
    created_at: "2026-07-19T02:00:00Z",
    updated_at: null,
    deleted_at: null,
    ...over,
  };
}

describe("TakeawayOrdersView", () => {
  it("lists takeaway orders with code, customer name, phone and count", () => {
    render(
      <TakeawayOrdersView
        orders={[
          makeOrder({
            order_code: "ORD-1001",
            customer: { id: "c1", first_name: "An", last_name: "Nguyen", phone: "0900111222" },
          }),
        ]}
        onOpenOrder={() => {}}
        currencyCode="VND"
      />,
      { wrapper: Wrapper },
    );
    expect(screen.getByText("ORD-1001")).toBeTruthy();
    expect(screen.getByText("An Nguyen")).toBeTruthy();
    expect(screen.getByText("0900111222")).toBeTruthy();
    // Header count badge shows the number.
    expect(screen.getByText("1")).toBeTruthy();
  });

  it("prefers the walk-in takeaway name/phone over the linked customer", () => {
    render(
      <TakeawayOrdersView
        orders={[
          makeOrder({
            customer_takeaway_name: "Chị Hoa",
            customer_takeaway_phone: "0912345678",
            customer: { id: "c9", first_name: "Old", last_name: "Name", phone: "0000" },
          }),
        ]}
        onOpenOrder={() => {}}
      />,
      { wrapper: Wrapper },
    );
    expect(screen.getByText("Chị Hoa")).toBeTruthy();
    expect(screen.getByText("0912345678")).toBeTruthy();
    expect(screen.queryByText("Old Name")).toBeNull();
  });

  it("shows the full finalized order code (not truncated)", () => {
    render(
      <TakeawayOrdersView
        orders={[makeOrder({ order_code: "ORD-2026-4234" })]}
        onOpenOrder={() => {}}
      />,
      { wrapper: Wrapper },
    );
    // The exact, complete code must be present as its own text node.
    expect(screen.getByText("ORD-2026-4234")).toBeTruthy();
  });

  it("shows the 'code pending' badge instead of a provisional WS- code", () => {
    render(
      <TakeawayOrdersView
        orders={[makeOrder({ order_code: "WS-A1B2-20260719-004" })]}
        onOpenOrder={() => {}}
      />,
      { wrapper: Wrapper },
    );
    // vi label for pos.order.code_pending.
    expect(screen.getByText("Đang cấp mã…")).toBeTruthy();
    // The internal provisional code must NOT leak to the operator.
    expect(screen.queryByText("WS-A1B2-20260719-004")).toBeNull();
  });

  it("renders the order note when present", () => {
    render(
      <TakeawayOrdersView
        orders={[makeOrder({ note: "Ít đá, nhiều đường" })]}
        onOpenOrder={() => {}}
      />,
      { wrapper: Wrapper },
    );
    expect(screen.getByText("Ít đá, nhiều đường")).toBeTruthy();
  });

  it("shows the walk-in label when the order has no customer", () => {
    render(
      <TakeawayOrdersView
        orders={[makeOrder({ customer: undefined })]}
        onOpenOrder={() => {}}
      />,
      { wrapper: Wrapper },
    );
    expect(screen.getByText("Khách lẻ")).toBeTruthy();
  });

  it("shows the empty state when there are no takeaway orders", () => {
    render(<TakeawayOrdersView orders={[]} onOpenOrder={() => {}} />, {
      wrapper: Wrapper,
    });
    expect(screen.getByText("Chưa có đơn takeaway nào đang xử lý.")).toBeTruthy();
  });

  it("filters by order code / customer / phone via the search box", () => {
    render(
      <TakeawayOrdersView
        orders={[
          makeOrder({
            id: "o1",
            order_code: "ORD-1001",
            customer: { id: "c1", first_name: "An", last_name: null, phone: "0900111222" },
          }),
          makeOrder({
            id: "o2",
            order_code: "ORD-2002",
            customer: { id: "c2", first_name: "Binh", last_name: null, phone: "0933444555" },
          }),
        ]}
        onOpenOrder={() => {}}
      />,
      { wrapper: Wrapper },
    );
    const search = screen.getByPlaceholderText("Tìm theo mã đơn, tên khách hoặc SĐT");

    fireEvent.change(search, { target: { value: "binh" } });
    expect(screen.queryByText("ORD-1001")).toBeNull();
    expect(screen.getByText("ORD-2002")).toBeTruthy();

    fireEvent.change(search, { target: { value: "0900" } });
    expect(screen.getByText("ORD-1001")).toBeTruthy();
    expect(screen.queryByText("ORD-2002")).toBeNull();

    fireEvent.change(search, { target: { value: "zzz" } });
    expect(screen.getByText("Không tìm thấy đơn phù hợp.")).toBeTruthy();
  });

  it("clicking a card opens that order's detail", () => {
    const onOpenOrder = vi.fn();
    render(
      <TakeawayOrdersView
        orders={[makeOrder({ id: "o42", order_code: "ORD-42" })]}
        onOpenOrder={onOpenOrder}
      />,
      { wrapper: Wrapper },
    );
    fireEvent.click(screen.getByText("ORD-42").closest("button")!);
    expect(onOpenOrder).toHaveBeenCalledWith("o42");
  });

  it("sorts newest-first by created_at", () => {
    render(
      <TakeawayOrdersView
        orders={[
          makeOrder({ id: "old", order_code: "ORD-OLD", created_at: "2026-07-19T01:00:00Z" }),
          makeOrder({ id: "new", order_code: "ORD-NEW", created_at: "2026-07-19T05:00:00Z" }),
        ]}
        onOpenOrder={() => {}}
      />,
      { wrapper: Wrapper },
    );
    const list = screen.getByRole("list");
    const codes = within(list)
      .getAllByText(/ORD-(OLD|NEW)/)
      .map((el) => el.textContent);
    expect(codes).toEqual(["ORD-NEW", "ORD-OLD"]);
  });
});
