import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { setActiveCurrency } from "../lib/totals";
import { OrderDetail } from "./order-history-shared";

/**
 * #2138 — các dòng tiền phải HIỆN khi sai, không được biến mất.
 *
 * Gate cũ là `> 0`, và nó che HAI thứ khác nhau:
 *
 *  1. **số âm.** Giảm giá / phí phục vụ / thuế âm là DỮ LIỆU HỎNG, không phải
 *     "không có". Lỗi cắt cụt khi chia bill (#2130) sinh ra `tax = -1` trên đơn
 *     thuế 0%, và dưới gate cũ POS vẽ ra… không gì cả. Người thu ngân không thể
 *     báo cáo một dòng chưa từng được vẽ.
 *  2. **nhóm 非課税 / zero-rated**, vốn có nền > 0 và thuế = 0, và là trường BẮT
 *     BUỘC trên chứng từ (Peppol BR-Z-08 / BR-E-08). Cloud đã sửa đúng lỗi này ở
 *     #2074; `order-cart.tsx` vẫn lọc chúng đi cho tới #2138.
 *
 * Bài test đi qua `OrderDetail` — thành phần THẬT mà thu ngân nhìn — chứ không
 * gọi thẳng hàm lọc. Lọc là chi tiết cài đặt; thứ đáng ghim là "con số có xuất
 * hiện trên màn hình hay không".
 */
const useOrder = vi.fn();
const useTableOrders = vi.fn();
vi.mock("@/hooks/api/use-orders", () => ({
  useOrder: (...a: unknown[]) => useOrder(...a),
  useTableOrders: (...a: unknown[]) => useTableOrders(...a),
}));

beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
  setActiveCurrency("VND");
  useOrder.mockReset();
  useTableOrders.mockReset();
});

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

const detail = (over: Record<string, unknown> = {}) => ({
  id: "o1",
  order_code: "ORD-2026-4231",
  order_type: "dine_in",
  status: "closed",
  subtotal: 2000,
  discount_amount: 0,
  service_charge: 0,
  tax_amount: 0,
  total_amount: 2000,
  paid_amount: 2000,
  remaining_amount: "0",
  is_tax_included: false,
  created_at: "2026-07-20T05:44:00Z",
  opened_at: "2026-07-20T05:00:00Z",
  items: [],
  ...over,
});

function renderDetail(over: Record<string, unknown> = {}) {
  useOrder.mockReturnValue({ data: { data: detail(over) }, isLoading: false });

  return render(
    <OrderDetail
      shopSlug="shop-a"
      orderId="o1"
      onBack={() => {}}
      t={(k: string) => k}
      locale="vi"
    />,
    { wrapper: Wrapper },
  );
}

describe("dòng tiền trên phiếu (#2138)", () => {
  it("THUẾ ÂM phải hiện — đó là dữ liệu hỏng, không phải vắng mặt", () => {
    // Đúng con số mà lỗi cắt cụt chia bill (#2130) sinh ra.
    renderDetail({ tax_amount: -1 });

    expect(screen.getByText("pos.table_history.tax")).toBeInTheDocument();
  });

  it("GIẢM GIÁ ÂM phải hiện", () => {
    renderDetail({ discount_amount: -500 });

    expect(screen.getByText("pos.table_history.discount")).toBeInTheDocument();
  });

  it("PHÍ PHỤC VỤ ÂM phải hiện", () => {
    renderDetail({ service_charge: -300 });

    expect(screen.getByText("pos.table_history.service")).toBeInTheDocument();
  });

  it("nhóm 非課税 (nền > 0, thuế = 0) phải hiện — BR-Z-08", () => {
    renderDetail({
      tax_breakdown: [
        { rate: 10, taxable: 1000, tax: 100 },
        { rate: 0, taxable: 900, tax: 0 },
      ],
    });

    // Cả hai nhóm, kể cả nhóm thuế suất 0.
    expect(screen.getAllByText("pos.table_history.tax_rate")).toHaveLength(2);
  });

  it("đơn thật sự KHÔNG có giảm giá / phí / thuế thì vẫn gọn — 0 không phải là lỗi", () => {
    // Mặt kia của bánh cóc: nếu bản sửa được cài thành "cứ có trường là hiện"
    // thì mọi đơn bình thường mọc thêm ba dòng 0, và ba dòng 0 lặp lại trên mọi
    // phiếu là cách một chỉ báo mất hết tác dụng.
    renderDetail();

    expect(screen.queryByText("pos.table_history.discount")).toBeNull();
    expect(screen.queryByText("pos.table_history.service")).toBeNull();
    expect(screen.queryByText("pos.table_history.tax")).toBeNull();
  });
});
