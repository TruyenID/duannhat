import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { setActiveCurrency } from "../lib/totals";
import { TableHistoryView } from "./table-history-view";

// Mock the two data hooks the view uses — the component owns the presentation,
// the hooks own the fetch.
const useTableOrders = vi.fn();
const useOrder = vi.fn();
vi.mock("@/hooks/api/use-orders", () => ({
  useTableOrders: (...a: unknown[]) => useTableOrders(...a),
  useOrder: (...a: unknown[]) => useOrder(...a),
}));

// #3040 — màn theo bàn nay CÓ nút in. Dịch vụ in phải được giả lập, nếu không
// `ReprintButton` sẽ probe workstation thật trong test.
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: {
    enabled: true,
    printPaymentReceipt: vi.fn(),
    printKitchenReprint: vi.fn(),
    printOrderBill: vi.fn(),
    printRedInvoice: vi.fn(),
    getPrintStatus: vi.fn().mockResolvedValue({ printed: {} }),
  },
}));

beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
  setActiveCurrency("VND");
  useTableOrders.mockReset();
  useOrder.mockReset();
});

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

const listOrder = (over: Record<string, unknown> = {}) => ({
  id: "o1",
  order_code: "ORD-2026-4231",
  order_type: "dine_in",
  status: "closed",
  total_amount: 5995,
  paid_amount: 5995,
  remaining_amount: "0",
  created_at: "2026-07-20T05:44:00Z",
  opened_at: "2026-07-20T05:00:00Z",
  tables: [{ id: "table-A" }],
  ...over,
});

function renderView() {
  return render(
    <TableHistoryView
      shopSlug="shop-a"
      table={{ id: "table-A", name: "A-02" }}
      onClose={() => {}}
    />,
    { wrapper: Wrapper },
  );
}

describe("TableHistoryView", () => {
  it("lists the table's orders and the period summary", () => {
    useTableOrders.mockReturnValue({
      data: { data: [listOrder(), listOrder({ id: "o2", order_code: "ORD-2026-4102", total_amount: 3200, paid_amount: 3200 })] },
      isLoading: false,
    });
    useOrder.mockReturnValue({ data: undefined, isLoading: true });

    renderView();

    expect(screen.getByText("ORD-2026-4231")).toBeTruthy();
    expect(screen.getByText("ORD-2026-4102")).toBeTruthy();
    // Header: "Bàn A-02 · Lịch sử" + "2 đơn · Doanh thu …"
    expect(screen.getByText("Bàn A-02 · Lịch sử")).toBeTruthy();
    expect(screen.getByText(/2 đơn/)).toBeTruthy();
  });

  it("shows the selected order's items + payments on the right", () => {
    useTableOrders.mockReturnValue({ data: { data: [listOrder()] }, isLoading: false });
    useOrder.mockReturnValue({
      isLoading: false,
      data: {
        data: {
          ...listOrder(),
          subtotal: 5995,
          tax_amount: 0,
          service_charge: 0,
          discount_amount: 0,
          items: [
            {
              id: "it1",
              status: "served",
              quantity: 2,
              subtotal: 4000,
              product_sku: { name: "Đá", product: { name: "Cà phê" } },
            },
          ],
          payments: [
            {
              id: "p1",
              status: "confirmed",
              amount: 5995,
              payment_method: "cash",
              payment_method_name: "Cash",
              tendered_amount: 6000,
              change_amount: 5,
              paid_at: "2026-07-20T05:45:00Z",
              created_at: "2026-07-20T05:45:00Z",
            },
          ],
        },
      },
    });

    renderView();

    // Item line (auto-selected newest order) — product + variant so the
    // operator sees WHICH variant (Đá vs Nóng), not just "Cà phê".
    expect(screen.getByText("Cà phê — Đá")).toBeTruthy();
    expect(screen.getByText("2×")).toBeTruthy();
    // Payment: resolved method name + status label.
    expect(screen.getByText("Cash")).toBeTruthy();
    expect(screen.getByText("Đã xác nhận")).toBeTruthy();
  });

  it("spells out the order story: created / added / voided-why / cash paid", () => {
    useTableOrders.mockReturnValue({ data: { data: [listOrder()] }, isLoading: false });
    useOrder.mockReturnValue({
      isLoading: false,
      data: {
        data: {
          ...listOrder(),
          status: "closed",
          opened_at: "2026-07-20T05:00:00Z",
          closed_at: "2026-07-20T05:50:00Z",
          subtotal: 4000,
          items: [
            {
              id: "it1",
              status: "served",
              quantity: 2,
              subtotal: 4000,
              created_at: "2026-07-20T05:05:00Z",
              product_sku: { name: null, product: { name: "Cà phê" } },
            },
            {
              id: "it2",
              status: "voided",
              quantity: 1,
              subtotal: 0,
              created_at: "2026-07-20T05:06:00Z",
              voided_at: "2026-07-20T05:20:00Z",
              void_reason: "khách đổi ý",
              product_sku: { name: null, product: { name: "Trà" } },
            },
          ],
          payments: [
            {
              id: "p1",
              status: "confirmed",
              amount: 4000,
              payment_method_name: "Cash",
              tendered_amount: 5000,
              change_amount: 1000,
              paid_at: "2026-07-20T05:50:00Z",
              created_at: "2026-07-20T05:50:00Z",
            },
          ],
        },
      },
    });

    renderView();

    // Lifecycle labels present.
    expect(screen.getByText("Tạo lúc")).toBeTruthy();
    expect(screen.getByText("Hoàn tất")).toBeTruthy();
    // Item added-time (locale time rendering; assert the label prefix appears).
    expect(screen.getByText(/Thêm lúc/)).toBeTruthy();
    // Voided item: time + reason.
    expect(screen.getByText(/Huỷ lúc .*khách đổi ý/)).toBeTruthy();
    expect(screen.getByText("Trà")).toBeTruthy();
    // Cash payment: tendered + change spelled out.
    expect(screen.getByText(/Khách đưa/)).toBeTruthy();
    expect(screen.getByText(/Thối lại/)).toBeTruthy();
  });

  it("shows promotion, coupon and per-rate tax detail", () => {
    useTableOrders.mockReturnValue({ data: { data: [listOrder()] }, isLoading: false });
    useOrder.mockReturnValue({
      isLoading: false,
      data: {
        data: {
          ...listOrder(),
          status: "closed",
          subtotal: 5000,
          discount_amount: 500,
          service_charge: 250,
          tax_amount: 545,
          is_tax_included: true,
          coupon_code_snapshot: "WELCOME10",
          coupon_name: "Welcome 10% off",
          coupon_discount_type: "percent",
          coupon_discount_value: 10,
          coupon_max_discount_cap: 50000,
          coupon_discount: 500,
          coupon_applied_at: "2026-07-20T05:30:00Z",
          tax_breakdown: [
            { rate: 10, taxable: 4500, tax: 450 },
            { rate: 8, taxable: 1000, tax: 80 },
          ],
          items: [
            {
              id: "it1",
              status: "served",
              quantity: 1,
              unit_price: 4000,
              original_unit_price: 5000, // 20% off, derived
              subtotal: 4000,
              created_at: "2026-07-20T05:05:00Z",
              applied_promotion_snapshot: { name: "Happy Hour" },
              product_sku: { name: null, product: { name: "Cà phê" } },
            },
          ],
          payments: [],
        },
      },
    });

    renderView();

    // Promotion name + derived percent on the item.
    expect(screen.getByText(/Happy Hour/)).toBeTruthy();
    expect(screen.getByText(/Khuyến mãi −20%/)).toBeTruthy();
    // Coupon: name + code + the rule (10% up to …).
    expect(screen.getByText("Welcome 10% off")).toBeTruthy();
    expect(screen.getByText("(WELCOME10)")).toBeTruthy();
    expect(screen.getByText(/10% · tối đa/)).toBeTruthy();
    // Per-rate tax lines.
    expect(screen.getByText("Thuế 10%")).toBeTruthy();
    expect(screen.getByText("Thuế 8%")).toBeTruthy();
    expect(screen.getByText("Giá đã bao gồm thuế")).toBeTruthy();
  });

  it("switches the date window", () => {
    useTableOrders.mockReturnValue({ data: { data: [] }, isLoading: false });
    useOrder.mockReturnValue({ data: undefined, isLoading: false });

    renderView();
    // Default window is 30 days; clicking "Hôm nay" re-invokes the hook with a
    // date_from bound (last call's dateFrom differs from the "all" case).
    fireEvent.click(screen.getByText("Hôm nay"));
    const lastCall = useTableOrders.mock.calls.at(-1);
    expect(lastCall?.[2]?.dateFrom).toBeTruthy();
  });

  it("shows the empty state when the table has no orders", () => {
    useTableOrders.mockReturnValue({ data: { data: [] }, isLoading: false });
    useOrder.mockReturnValue({ data: undefined, isLoading: false });

    renderView();
    expect(
      screen.getByText("Bàn này chưa có đơn nào trong khoảng thời gian đã chọn."),
    ).toBeTruthy();
  });

  it("keeps only orders bound to this table (client safety filter)", () => {
    useTableOrders.mockReturnValue({
      data: {
        data: [
          listOrder({ id: "mine", order_code: "ORD-MINE", tables: [{ id: "table-A" }] }),
          listOrder({ id: "other", order_code: "ORD-OTHER", tables: [{ id: "table-B" }] }),
        ],
      },
      isLoading: false,
    });
    useOrder.mockReturnValue({ data: undefined, isLoading: true });

    renderView();
    expect(screen.getByText("ORD-MINE")).toBeTruthy();
    expect(screen.queryByText("ORD-OTHER")).toBeNull();
  });
});

/**
 * #3040 — cờ `allowReprint` phải được TRUYỀN, không chỉ tồn tại.
 *
 * Bài ở `order-reprint.test.tsx` render thẳng `OrderDetail` với cờ bật, nên nó
 * chứng minh điều kiện QUANH nút — không chứng minh màn này có bật cờ hay
 * không. Gỡ một chữ `allowReprint` khỏi `table-history-view.tsx` thì mọi bài
 * kia vẫn xanh, và quán mất lại đúng cái nút vừa được chốt cho họ.
 *
 * Đây cũng là màn đảo một ruling cũ (màn theo bàn cố ý không có nút in), nên nó
 * càng cần một bài ghim: người đọc `CLAUDE.md` bản cũ sẽ thấy dòng đó "sai" và
 * sửa lại cho "đúng".
 */
describe("#3040 nút in trên màn bán hàng", () => {
  it("truyền allowReprint xuống OrderDetail cho đơn đã đóng", async () => {
    useTableOrders.mockReturnValue({
      data: { data: [listOrder()] },
      isLoading: false,
    });
    useOrder.mockReturnValue({
      data: {
        data: {
          ...listOrder(),
          subtotal: 5995,
          items: [{ id: "i1", quantity: 1, subtotal: 5995, name: "Cà phê", status: "ordered" }],
          // Hình dạng của đơn trả ONLINE: không có dòng payment cục bộ, chỉ có
          // bản Cloud trộn xuống.
          payments: [
            {
              id: "cloud-p1",
              amount: 5995,
              status: "succeeded",
              payment_method_name: "Thẻ (online)",
              created_at: "2026-07-20T05:40:00Z",
            },
          ],
        },
      },
      isLoading: false,
    });

    renderView();
    fireEvent.click(screen.getAllByText("ORD-2026-4231")[0]!);

    // Nhãn THẬT, không phải khoá: màn này bọc trong `AppProvider` nên
    // `useTranslation` trả tiếng Việt. Chấp nhận cả ba trạng thái nút vì tally
    // đến từ một lượt probe bất đồng bộ — thứ bài này ghim là nút CÓ MẶT.
    // `findAllByText`: cặp `In gốc` / `In lại` cùng có mặt (#2535 A7), nên
    // `findByText` sẽ ném vì khớp nhiều phần tử. Thứ bài này ghim là nút CÓ
    // MẶT trên màn theo bàn, không phải nút nào đang bật.
    const receipt = await screen.findAllByText(/In gốc|In lại|In hoá đơn/);
    expect(receipt.length).toBeGreaterThan(0);
  });
});
