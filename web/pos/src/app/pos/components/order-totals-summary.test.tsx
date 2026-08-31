import { beforeEach, describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { OrderTotalsSummary } from "./order-totals-summary";
import { setActiveCurrency } from "../lib/totals";
import type { CustomerOrder } from "../types";

/*
 * Khối tách tiền trong hộp thoại thu tiền. Nó tồn tại vì luồng MỘT CHẠM: thu
 * ngân đi thẳng từ giỏ hàng vào màn thu tiền, không còn ngang qua màn soạn giảm
 * giá, nên đây là chỗ DUY NHẤT họ còn nhìn thấy thuế và phí phục vụ trước khi
 * nhận tiền.
 *
 * Ép locale vi + tiền tệ JPY để nhãn và định dạng tất định.
 */
beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
  setActiveCurrency("JPY");
});

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

const order = (over: Record<string, unknown>) =>
  ({
    subtotal: "0",
    discount_amount: "0",
    service_charge: "0",
    tax_amount: "0",
    total_amount: "0",
    ...over,
  }) as unknown as CustomerOrder;

/** ORD-2026-3152: ¥1.150 内税 10% + phí phục vụ 5% → tổng ¥1.208. */
const includedOrder = order({
  subtotal: "1150.00",
  service_charge: "58.00",
  tax_amount: "110.00",
  total_amount: "1208.00",
  is_tax_included: true,
  tax_breakdown: [{ rate: 10, taxable: 1045, tax: 105 }],
});

describe("OrderTotalsSummary — ảnh chụp 内税", () => {
  it("hiện tạm tính, phí phục vụ và tổng thu", () => {
    render(
      <OrderTotalsSummary order={includedOrder} pricesIncludeTax />,
      { wrapper: Wrapper },
    );

    expect(screen.getByText("Tạm tính")).toBeInTheDocument();
    expect(screen.getByText("Phí phục vụ")).toBeInTheDocument();
    expect(screen.getByText("Tổng thu")).toBeInTheDocument();
  });

  it("phí phục vụ hiện ¥58 với dòng con ¥53 — KHÔNG phải ¥63", () => {
    const { container } = render(
      <OrderTotalsSummary order={includedOrder} pricesIncludeTax />,
      { wrapper: Wrapper },
    );

    const text = container.textContent ?? "";
    expect(text).toContain("￥58");
    expect(text).toContain("￥53");
    expect(text).not.toContain("￥63");
  });

  it("KHÔNG vẽ dòng làm tròn — thuế 内税 không phải số hạng nên không có gì để bù", () => {
    render(
      <OrderTotalsSummary order={includedOrder} pricesIncludeTax />,
      { wrapper: Wrapper },
    );

    expect(screen.queryByText("Làm tròn")).toBeNull();
  });

  it("thuế xuống ghi chú 内税, không nằm trong cột cộng", () => {
    render(
      <OrderTotalsSummary order={includedOrder} pricesIncludeTax />,
      { wrapper: Wrapper },
    );

    // Nhãn cột cộng (`pos.cart.tax_rate` → "Thuế 10%") vắng mặt; nhãn ghi chú
    // (`pos.cart.tax_rate_included` → "Đã gồm thuế 10%") có mặt. Hai khoá i18n
    // khác nhau, đúng như giỏ hàng vẽ.
    expect(screen.queryByText("Thuế 10%")).toBeNull();
    expect(screen.getByText("Đã gồm thuế 10%")).toBeInTheDocument();
  });
});

describe("OrderTotalsSummary — ảnh chụp net, quán hiện 税抜", () => {
  const netOrder = order({
    subtotal: "893",
    service_charge: "45",
    tax_amount: "94",
    total_amount: "1032",
    is_tax_included: false,
    tax_breakdown: [{ rate: 10, taxable: 893, tax: 89 }],
  });

  it("thuế LÀ một dòng cộng, phí phục vụ gộp thuế lên (45 + 5 = 50)", () => {
    const { container } = render(
      <OrderTotalsSummary order={netOrder} pricesIncludeTax={false} />,
      { wrapper: Wrapper },
    );

    expect(screen.getByText("Thuế 10%")).toBeInTheDocument();
    const text = container.textContent ?? "";
    expect(text).toContain("￥50");
  });
});

describe("OrderTotalsSummary — giảm giá", () => {
  it("vẽ dòng giảm giá mang dấu trừ khi đơn có mã", () => {
    const discounted = order({
      subtotal: "1000",
      discount_amount: "150",
      total_amount: "850",
      is_tax_included: true,
      tax_breakdown: [{ rate: 10, taxable: 773, tax: 77 }],
    });

    render(
      <OrderTotalsSummary order={discounted} pricesIncludeTax />,
      { wrapper: Wrapper },
    );

    expect(screen.getByText("Giảm giá")).toBeInTheDocument();
    expect(screen.getByText(/−.*150/)).toBeInTheDocument();
  });
});
