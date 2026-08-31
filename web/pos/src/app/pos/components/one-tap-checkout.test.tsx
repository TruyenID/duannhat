import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { useState, type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { OrderCart, type OrderCartProps } from "./order-cart";
import { PaymentDialog } from "./payment-dialog";
import { setActiveCurrency } from "../lib/totals";
import type { CustomerOrder, EffectivePaymentOption, PaymentMethod } from "../types";

/**
 * MỘT CHẠM — giỏ hàng → màn thu tiền, không dừng ở đâu giữa chừng.
 *
 * Trước đây thu ngân bấm ba lần: "Tính tiền" (chỉ mở form mã giảm giá) →
 * "Chốt đơn" (`POST /checkout`) → "Thu tiền" (chỉ mở hộp thoại). Chạm 1 và 3
 * không mang quyết định nào.
 *
 * File này ghim CHUỖI NỐI, không phải từng mảnh: hai component thật gắn cạnh
 * nhau đúng như `page.tsx` gắn chúng, một cú chạm, rồi kiểm rằng hộp thoại thu
 * tiền đã mở **và mang bảng tách tiền**. Từng mảnh xanh riêng lẻ mà dây nối đứt
 * là đúng hình dạng lỗi #683 đã sinh ra thư mục test này.
 */
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: { printPaymentReceipt: vi.fn(() => Promise.resolve()) },
}));

vi.mock("@/hooks/api/use-shop-payment-methods", () => ({
  useShopPaymentMethods: () => ({ data: [] }),
}));

// Hộp thoại tự đọc `prices_include_tax` (cùng query key với page → dedupe).
// Ở test thì chặn ở tầng hook cho tất định.
vi.mock("@/hooks/api/use-shop-order-settings", () => ({
  useShopOrderSettings: () => ({ data: { data: { prices_include_tax: true } } }),
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

const cash: PaymentMethod = {
  id: "pm-cash",
  code: "cash",
  name: "Tiền mặt",
  type: "cash",
  is_auto_confirm: true,
  requires_tendered: true,
  is_active: true,
  sort_order: 0,
  branch_id: null,
  organization_id: "org-1",
} as unknown as PaymentMethod;

const cashOption = {
  id: "opt-cash",
  payment_method: cash,
  effective: true,
  client: { supports_pos_checkout: true },
} as unknown as EffectivePaymentOption;

/** ORD-2026-3152: ¥1.150 内税 10% + phí phục vụ 5% → tổng ¥1.208. */
const order = {
  id: "ord-1",
  order_code: "ORD-2026-3152",
  status: "open",
  subtotal: "1150.00",
  discount_amount: "0.00",
  service_charge: "58.00",
  tax_amount: "110.00",
  total_amount: "1208.00",
  paid_amount: "0",
  remaining_amount: "1208",
  total_tip: "0",
  is_tax_included: true,
  tax_breakdown: [{ rate: 10, taxable: 1045, tax: 105 }],
  guest_count: 1,
  items: [
    {
      id: "it-1",
      status: "served",
      quantity: 1,
      unit_price: "1150",
      subtotal: "1150",
      toppings: [],
      product_sku: { name: null, product: { name: "Bánh mì thịt viên" } },
    },
  ],
} as unknown as CustomerOrder;

/**
 * Gắn giỏ hàng + hộp thoại thu tiền như `page.tsx` gắn chúng: `onPay` bật cờ
 * mở hộp thoại, và `onCheckout` là mutation thật (ở đây được giả lập) trả về
 * đơn đã chuyển trạng thái.
 */
function mountPos(
  over: Partial<OrderCartProps> = {},
  initialStatus = "open",
) {
  const onCheckout = vi.fn(() => Promise.resolve(true));

  function Harness() {
    const [payOpen, setPayOpen] = useState(false);
    // Checkout thành công thì server trả đơn ở trạng thái `checkout` và mutation
    // cache-set nó. Mô phỏng đúng vậy — nếu không, mọi thứ rẽ theo trạng thái
    // sau khi chốt (ô mã giảm giá, nút Chia bill) đều không được kiểm.
    const [status, setStatus] = useState<string>(initialStatus);
    const live = { ...order, status } as unknown as CustomerOrder;

    return (
      <>
        <OrderCart
          {...({
            order: live,
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
            onCheckout: async (draft: { discount_amount: number }) => {
              const moved = await onCheckout(draft);
              if (moved) setStatus("checkout");

              return moved;
            },
            onApplyCoupon: vi.fn(() => Promise.resolve()),
            onReleaseCoupon: vi.fn(() => Promise.resolve()),
            onPay: () => setPayOpen(true),
            onSplitBill: vi.fn(),
            onVoid: vi.fn(),
            onAssignTable: vi.fn(),
            onEditGuestCount: vi.fn(),
            onChangeTable: vi.fn(),
            onMergeTable: vi.fn(),
            onUnmergeTable: vi.fn(),
            pricesIncludeTax: true,
            ...over,
          } as unknown as OrderCartProps)}
        />
        <PaymentDialog
          open={payOpen}
          onOpenChange={setPayOpen}
          shopSlug="sjk"
          order={live}
          options={[cashOption]}
          optionsLoading={false}
          policyRevision={1}
          outstanding={[]}
          outstandingLoading={false}
          onCreatePayment={vi.fn(() => Promise.resolve())}
        />
      </>
    );
  }

  render(<Harness />, { wrapper: Wrapper });

  return { onCheckout };
}

describe("một chạm: giỏ hàng → màn thu tiền", () => {
  it("một cú chạm vào 'Tính tiền' mở màn thu tiền", async () => {
    const { onCheckout } = mountPos();

    // Trước khi chạm: chưa có hộp thoại nào.
    expect(screen.queryByRole("dialog")).toBeNull();

    fireEvent.click(screen.getByRole("button", { name: /Tính tiền/ }));

    await waitFor(() => expect(onCheckout).toHaveBeenCalledTimes(1));
    await waitFor(() =>
      expect(screen.getByRole("dialog")).toBeInTheDocument(),
    );
  });

  it("màn thu tiền mang bảng tách — thu ngân không nhận tiền trong mù mờ", async () => {
    // Đây là lý do khối tách tiền phải tồn tại: luồng một chạm bỏ qua màn soạn
    // giảm giá, vốn là chỗ DUY NHẤT trước đây hiện thuế và phí phục vụ.
    mountPos();

    fireEvent.click(screen.getByRole("button", { name: /Tính tiền/ }));

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("Tạm tính");
    expect(dialog).toHaveTextContent("Phí phục vụ");
    expect(dialog).toHaveTextContent("Tổng thu");

    // Số đúng của chế độ 内税: phí ¥58 (thuế ¥5 nằm trong), KHÔNG phải ¥63,
    // và không có dòng làm tròn nào bù trừ.
    expect(dialog.textContent).toContain("￥58");
    expect(dialog.textContent).not.toContain("￥63");
    expect(dialog.textContent).not.toContain("Làm tròn");
  });

  it("checkout thất bại → KHÔNG mở màn thu tiền", async () => {
    const onCheckout = vi.fn(() => Promise.resolve(false));
    mountPos({ onCheckout } as unknown as Partial<OrderCartProps>);

    fireEvent.click(screen.getByRole("button", { name: /Tính tiền/ }));

    await waitFor(() => expect(onCheckout).toHaveBeenCalledTimes(1));
    expect(screen.queryByRole("dialog")).toBeNull();
  });

  it("chạm liên tiếp chỉ gọi checkout MỘT lần", async () => {
    // `checkout` chỉ nhận `open|dining`; cú chạm thứ hai trên tablet cảm ứng sẽ
    // ăn 409. Cờ `checkoutPending` phải chặn ngay tại nguồn.
    let resolveCheckout: ((v: boolean) => void) | undefined;
    const onCheckout = vi.fn(
      () =>
        new Promise<boolean>((res) => {
          resolveCheckout = res;
        }),
    );
    mountPos({ onCheckout } as unknown as Partial<OrderCartProps>);

    const btn = screen.getByRole("button", { name: /Tính tiền/ });
    fireEvent.click(btn);
    fireEvent.click(btn);
    fireEvent.click(btn);

    expect(onCheckout).toHaveBeenCalledTimes(1);
    await waitFor(() => expect(btn).toBeDisabled());

    resolveCheckout?.(true);
    await waitFor(() =>
      expect(screen.getByRole("dialog")).toBeInTheDocument(),
    );
  });
});

describe("mã giảm giá vẫn với tới được SAU khi đã chốt", () => {
  /*
   * Lỗ mà luồng một-chạm mở ra: chạm "Tính tiền" xong khách mới đưa mã. Đơn đã
   * sang `checkout`, ô nhập mã của giỏ chỉ render khi `draft && !isCheckingOut`,
   * và KHÔNG có route nào đưa đơn về `open` — trước đây kẹt cứng, chỉ còn cách
   * huỷ cả đơn nhập lại.
   *
   * Cả Cloud (`OrderCouponService::assertOrderModifiable`) lẫn workstation
   * (`OrderCouponMutable`) đều còn cho áp mã ở `checkout`, nên lỗ này là lỗ
   * GIAO DIỆN — và đây là chỗ nó được bịt.
   */
  it("ô nhập mã có mặt trong màn thu tiền của đơn vừa chốt", async () => {
    mountPos();

    fireEvent.click(screen.getByRole("button", { name: /Tính tiền/ }));

    const dialog = await screen.findByRole("dialog");
    await waitFor(() =>
      expect(dialog).toHaveTextContent("Giảm giá"),
    );
    // Ô nhập mã thật, không phải chỉ cái nhãn.
    expect(
      dialog.querySelector('input[type="text"], input:not([type])'),
    ).toBeTruthy();
  });

  it("ẩn ô nhập mã khi đơn đã sang `paying` — backend cũng từ chối trạng thái đó", async () => {
    // Đơn đã nhận một phần tiền: đổi tổng là làm sai khoản đã thu, nên hai đầu
    // đều loại `paying` khỏi allowlist. Giao diện phải KHÔNG MỜI người ta bấm.
    mountPos({}, "paying");

    // Đơn `paying` không có nút "Tính tiền"; mở thẳng màn tiền qua nút Thu.
    const payBtn = screen.getByRole("button", { name: /Thanh toán/ });
    fireEvent.click(payBtn);

    const dialog = await screen.findByRole("dialog");
    expect(dialog).toHaveTextContent("Tổng thu");
    expect(dialog).not.toHaveTextContent("Áp dụng");
  });
});
