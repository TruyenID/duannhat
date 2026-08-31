import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import type { ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { ApiError } from "@/lib/api";
import type { CustomerOrder } from "../types";

/*
 * Ô mã giảm giá trong màn thu tiền — thứ ĐÓNG lỗ mà luồng một-chạm mở ra.
 *
 * Kịch bản nó sinh ra để cứu: thu ngân chạm "Tính tiền", đơn sang `checkout`,
 * RỒI khách mới đưa mã. Trước đây kẹt cứng — ô nhập mã của giỏ chỉ render khi
 * `draft && !isCheckingOut`, và không có route nào đưa đơn về `open`.
 *
 * Mock ở tầng SERVICE chứ không phải tầng hook: như vậy `useApplyCoupon` thật
 * vẫn chạy, gồm cả `setQueryData` lên đúng cache key mà giỏ hàng đọc — đó mới
 * là điều khiến ô này không phải "đường thứ hai".
 */
const orderServiceMock = vi.hoisted(() => ({
  applyCoupon: vi.fn(),
  releaseCoupon: vi.fn(),
}));

vi.mock("@/services/order-service", () => ({
  orderService: orderServiceMock,
}));

// Import SAU khi mock đã đăng ký.
import { CouponField } from "./coupon-field";

const order = (over: Record<string, unknown> = {}) =>
  ({
    id: "ord-1",
    status: "checkout",
    subtotal: "1000",
    discount_amount: "0",
    service_charge: "0",
    tax_amount: "0",
    total_amount: "1000",
    coupon_id: null,
    customer_id: null,
    ...over,
  }) as unknown as CustomerOrder;

function Wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return (
    <QueryClientProvider client={qc}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
  orderServiceMock.applyCoupon.mockReset();
  orderServiceMock.releaseCoupon.mockReset();
});

describe("CouponField", () => {
  it("áp mã trên đơn ĐÃ CHỐT — đúng thứ trước đây không làm được", async () => {
    orderServiceMock.applyCoupon.mockResolvedValue({
      data: order({ discount_amount: "100", coupon_id: "c-1" }),
    });

    render(<CouponField shopSlug="sjk" order={order()} />, { wrapper: Wrapper });

    fireEvent.change(screen.getByRole("textbox"), {
      target: { value: "WELCOME10" },
    });
    fireEvent.click(screen.getByRole("button", { name: /Áp dụng/i }));

    await waitFor(() =>
      expect(orderServiceMock.applyCoupon).toHaveBeenCalledWith("sjk", "ord-1", {
        code: "WELCOME10",
        customer_id: null,
        downgrade_exclusive_promotions: undefined,
      }),
    );
  });

  it("cắt khoảng trắng và bỏ qua ô rỗng — không bắn request vô nghĩa", async () => {
    render(<CouponField shopSlug="sjk" order={order()} />, { wrapper: Wrapper });

    const apply = screen.getByRole("button", { name: /Áp dụng/i });
    fireEvent.click(apply);
    expect(orderServiceMock.applyCoupon).not.toHaveBeenCalled();

    orderServiceMock.applyCoupon.mockResolvedValue({ data: order() });
    fireEvent.change(screen.getByRole("textbox"), {
      target: { value: "  SAVE5  " },
    });
    fireEvent.click(apply);

    await waitFor(() =>
      expect(orderServiceMock.applyCoupon).toHaveBeenCalledWith(
        "sjk",
        "ord-1",
        expect.objectContaining({ code: "SAVE5" }),
      ),
    );
  });

  it("422 có cấu trúc → hiện thông điệp bản địa hoá, không phải lỗi trần", async () => {
    orderServiceMock.applyCoupon.mockRejectedValue(
      new ApiError(422, { error_code: "coupon_expired" }, "unprocessable"),
    );

    render(<CouponField shopSlug="sjk" order={order()} />, { wrapper: Wrapper });

    fireEvent.change(screen.getByRole("textbox"), {
      target: { value: "OLD" },
    });
    fireEvent.click(screen.getByRole("button", { name: /Áp dụng/i }));

    // `parseCouponError` rút `error_code` ra, CouponRow tra khoá
    // `coupon.error.<code>` — chứng minh dây nối lỗi còn nguyên, không bị nuốt.
    await waitFor(() =>
      expect(orderServiceMock.applyCoupon).toHaveBeenCalledTimes(1),
    );
    await waitFor(() =>
      expect(screen.getByText(/hết hạn|expired|coupon\.error/i)).toBeInTheDocument(),
    );
  });

  it("gỡ mã đã áp", async () => {
    orderServiceMock.releaseCoupon.mockResolvedValue({ data: order() });

    render(
      <CouponField
        shopSlug="sjk"
        order={order({ coupon_id: "c-1", coupon_code_snapshot: "WELCOME10" })}
      />,
      { wrapper: Wrapper },
    );

    // Ở trạng thái đã áp, CouponRow đổi sang chip mã + nút gỡ (không còn ô nhập).
    const remove = screen
      .getAllByRole("button")
      .find((b) => !/Áp dụng/i.test(b.textContent ?? ""));
    expect(remove).toBeDefined();
    fireEvent.click(remove!);

    await waitFor(() =>
      expect(orderServiceMock.releaseCoupon).toHaveBeenCalledWith("sjk", "ord-1"),
    );
  });
});
