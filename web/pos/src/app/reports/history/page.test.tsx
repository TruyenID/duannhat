import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { MemoryRouter, Route, Routes } from "react-router-dom";
import { AppProvider } from "@/providers/app-provider";
import { formatCurrency, setActiveCurrency } from "@/app/pos/lib/totals";
import { OrderHistoryPage } from "./page";

// The page owns the presentation (filter + master-detail); the hooks own the
// fetch. PosHeader pulls in the whole workstation/shift stack, irrelevant here.
const useAllOrdersHistory = vi.fn();
const useOrder = vi.fn();
vi.mock("@/hooks/api/use-orders", () => ({
  useAllOrdersHistory: (...a: unknown[]) => useAllOrdersHistory(...a),
  useOrder: (...a: unknown[]) => useOrder(...a),
}));
vi.mock("@/hooks/api/use-shop", () => ({
  useShop: () => ({ data: { data: { name: "Shinjuku" } } }),
}));
vi.mock("@/lib/shop-context", () => ({ setCurrentShopSlug: () => {} }));
vi.mock("@/app/pos/components/pos-header", () => ({ PosHeader: () => null }));

beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
  setActiveCurrency("VND");
  useAllOrdersHistory.mockReset();
  useOrder.mockReset();
  useOrder.mockReturnValue({ data: undefined, isLoading: true });
});

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
  tables: [{ id: "table-A", code: "A-02", name: "A-02" }],
  ...over,
});

type Extra = Partial<{
  hasNextPage: boolean;
  total: number;
  fetchNextPage: () => void;
}>;

function mockHistory(orders: Record<string, unknown>[], extra: Extra = {}) {
  const total = extra.total ?? orders.length;
  useAllOrdersHistory.mockReturnValue({
    data: {
      pages: [
        {
          data: orders,
          meta: {
            current_page: 1,
            last_page: extra.hasNextPage ? 2 : 1,
            per_page: 50,
            total,
          },
        },
      ],
    },
    isLoading: false,
    isFetching: false,
    hasNextPage: extra.hasNextPage ?? false,
    isFetchingNextPage: false,
    fetchNextPage: extra.fetchNextPage ?? vi.fn(),
    refetch: vi.fn(),
  });
}

function renderPage() {
  return render(
    <AppProvider>
      <MemoryRouter initialEntries={["/shop/shop-a/reports/history"]}>
        <Routes>
          <Route
            path="/shop/:shopSlug/reports/history"
            element={<OrderHistoryPage />}
          />
        </Routes>
      </MemoryRouter>
    </AppProvider>,
  );
}

describe("OrderHistoryPage", () => {
  it("lists orders across tables with a place label + total count", () => {
    mockHistory([
      listOrder(),
      listOrder({
        id: "o2",
        order_code: "ORD-2026-4102",
        order_type: "takeaway",
        tables: [],
      }),
    ]);

    renderPage();

    expect(screen.getByText("ORD-2026-4231")).toBeTruthy();
    expect(screen.getByText("ORD-2026-4102")).toBeTruthy();
    // Dine-in row shows the table name; takeaway row shows "Mang đi".
    expect(screen.getByText("A-02")).toBeTruthy();
    expect(screen.getAllByText("Mang đi").length).toBeGreaterThan(0);
    expect(screen.getByText(/2 đơn/)).toBeTruthy();
  });

  it("renders the auto-selected order's items + payments on the right", () => {
    mockHistory([listOrder()]);
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

    renderPage();

    expect(screen.getByText("Cà phê — Đá")).toBeTruthy();
    expect(screen.getByText("Cash")).toBeTruthy();
    expect(screen.getByText("Đã xác nhận")).toBeTruthy();
    // The selected order's table is shown prominently in the detail (the
    // "Vị trí:" chip only renders in the detail, so it uniquely proves it).
    expect(screen.getByText("Vị trí:")).toBeTruthy();
    expect(screen.getAllByText("A-02").length).toBeGreaterThanOrEqual(2);
  });

  it("lists each line's toppings: price, ×qty, − for a removal", () => {
    mockHistory([listOrder()]);
    useOrder.mockReturnValue({
      isLoading: false,
      data: {
        data: {
          ...listOrder(),
          subtotal: 5995,
          items: [
            {
              id: "it1",
              status: "served",
              quantity: 1,
              subtotal: 5995,
              product_sku: { name: "Đá", product: { name: "Cà phê" } },
              toppings: [
                {
                  id: "tp1",
                  topping_group_item_id: "tgi1",
                  name: "Trân châu",
                  modifier_type: "add",
                  quantity: 2,
                  unit_price: "5000",
                },
                {
                  id: "tp2",
                  topping_group_item_id: "tgi2",
                  // Old orders froze a mirrored snapshot name — history is
                  // exactly where they live, so it must collapse here too.
                  name: "Đường · Đường",
                  modifier_type: "remove",
                  quantity: 1,
                  unit_price: "0",
                },
              ],
            },
          ],
          payments: [],
        },
      },
    });

    renderPage();

    // Intl puts a NBSP before "₫"; testing-library normalizes it out of the
    // DOM text but not out of the expected string, so normalize ours too.
    const addRow = `Trân châu (${formatCurrency(5000)}) ×2`.replace(/\s+/g, " ");
    expect(screen.getByText(addRow)).toBeTruthy();
    // Removal: "−" prefix, no price tag, no ×1. Mirrored name collapsed.
    expect(screen.getByText("− Đường")).toBeTruthy();
  });

  it("switches granularity → refetches a wider window (day → month)", () => {
    mockHistory([]);
    renderPage();

    const dayCall = useAllOrdersHistory.mock.calls.at(-1);
    // Day granularity → a single-day window (from === to).
    expect(dayCall?.[1]?.dateFrom).toBe(dayCall?.[1]?.dateTo);

    // Radix Tabs switch on pointer/mouse-down, not a bare click.
    const monthTab = screen.getByRole("tab", { name: "Tháng" });
    fireEvent.pointerDown(monthTab);
    fireEvent.mouseDown(monthTab);
    fireEvent.click(monthTab);
    const monthCall = useAllOrdersHistory.mock.calls.at(-1);
    // Month granularity → a real range (from < to).
    expect(monthCall?.[1]?.dateFrom).not.toBe(monthCall?.[1]?.dateTo);
    expect(monthCall?.[1]?.dateFrom).toMatch(/-01$/);
  });

  it("paginates: shows 'Xem thêm' and fetches the next page on click", () => {
    const fetchNextPage = vi.fn();
    mockHistory([listOrder()], { hasNextPage: true, total: 60, fetchNextPage });

    renderPage();

    const more = screen.getByText(/Xem thêm/);
    expect(more).toBeTruthy();
    fireEvent.click(more);
    expect(fetchNextPage).toHaveBeenCalled();
  });

  it("shows the empty state when the period has no orders", () => {
    mockHistory([]);
    renderPage();
    expect(
      screen.getByText("Không có order nào trong kỳ này"),
    ).toBeTruthy();
  });
});
