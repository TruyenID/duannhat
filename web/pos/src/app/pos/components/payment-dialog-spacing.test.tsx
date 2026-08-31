import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { AppProvider } from "@/providers/app-provider";
import { PaymentDialog } from "./payment-dialog";
import type { CustomerOrder, EffectivePaymentOption, PaymentMethod } from "../types";

/*
 * Nhịp dọc ở đáy hộp thoại thu tiền.
 *
 * Đã đo trên trình duyệt thật (hộp thoại rộng 512px): khối máy quẹt thẻ kết
 * thúc ở y=570 và `DialogFooter` bắt đầu ở y=570 — nút "Quẹt thẻ (P400)" dán
 * KHÍT vào đường `border-t` của chân hộp thoại, **0 pixel** thở, trong khi mép
 * trên của nó có 13px. Nguyên nhân: khối dùng `pt-3` mà không có đệm dưới.
 *
 * jsdom không tính layout, nên bài test này ghim HỢP ĐỒNG LỚP CSS thay vì số
 * pixel: khối phải mang đệm dọc ĐỐI XỨNG (`py-*`), không phải đệm một phía
 * (`pt-*`). Đó đúng là điều kiện đã hỏng, và là điều kiện duy nhất jsdom kiểm
 * được một cách trung thực.
 */
vi.mock("@/services/workstation-print-service", () => ({
  workstationPrintService: { printPaymentReceipt: vi.fn(() => Promise.resolve()) },
}));

vi.mock("@/hooks/api/use-shop-payment-methods", () => ({
  useShopPaymentMethods: () => ({ data: [] }),
}));

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
});

const cash = {
  id: "pm-cash",
  code: "cash",
  name: "Tiền mặt",
  type: "cash",
  is_auto_confirm: true,
  requires_tendered: true,
  is_active: true,
  sort_order: 0,
} as unknown as PaymentMethod;

const cashOption = {
  id: "opt-cash",
  payment_method: cash,
  display_name: "Tiền mặt (sổ nội bộ)",
  effective: true,
  client: { supports_pos_checkout: true, requires_tendered: true },
} as unknown as EffectivePaymentOption;

const order = {
  id: "ord-1",
  order_code: "ORD-2026-3251",
  status: "checkout",
  subtotal: "400",
  discount_amount: "0",
  service_charge: "20",
  tax_amount: "38",
  total_amount: "420",
  paid_amount: "0",
  remaining_amount: "420",
  total_tip: "0",
  is_tax_included: true,
  tax_breakdown: [{ rate: 10, taxable: 364, tax: 36 }],
  guest_count: 1,
  items: [],
} as unknown as CustomerOrder;

function renderDialog() {
  render(
    <PaymentDialog
      open
      onOpenChange={vi.fn()}
      shopSlug="ningyocho"
      order={order}
      options={[cashOption]}
      optionsLoading={false}
      policyRevision={1}
      outstanding={[]}
      outstandingLoading={false}
      onCreatePayment={vi.fn(() => Promise.resolve())}
    />,
    { wrapper: Wrapper },
  );
}

/** Khối bao ngoài của một nút — nơi mang đệm của cả cụm. */
function blockOf(button: HTMLElement): HTMLElement {
  return button.parentElement!.parentElement as HTMLElement;
}

describe("đáy hộp thoại thu tiền — nhịp dọc", () => {
  it("khối máy quẹt thẻ có đệm dọc ĐỐI XỨNG, không phải đệm một phía", () => {
    renderDialog();

    const terminal = screen.getByRole("button", { name: /P400/ });
    const cls = blockOf(terminal).className;

    // `py-3` = đệm trên VÀ dưới. `pt-3` trần là đúng lỗi đã đo được.
    expect(cls).toContain("py-3");
    expect(cls).not.toMatch(/\bpt-\d/);
    expect(cls).not.toMatch(/\bpb-\d/);
  });

  it("khối đó dùng CÙNG nhịp dọc với chân hộp thoại", () => {
    renderDialog();

    const terminal = screen.getByRole("button", { name: /P400/ });
    const block = blockOf(terminal);
    const footer = block.nextElementSibling as HTMLElement;

    // Hai khối kề nhau qua một đường `border-t`; lệch nhịp là thứ mắt nhìn ra
    // ngay, và là thứ đã xảy ra.
    const rhythm = (el: HTMLElement) =>
      (el.className.match(/\bpy-(\d)\b/) ?? [])[1];

    expect(footer.className).toContain("border-t");
    expect(rhythm(block)).toBe(rhythm(footer));
    expect(rhythm(block)).toBeDefined();
  });

  it("khối máy quẹt thẻ vẫn giữ đường kẻ ngăn với phần cuộn phía trên", () => {
    // Đệm dưới được thêm vào KHÔNG được nuốt mất đường phân cách — nó là thứ
    // tách vùng cuộn khỏi cụm hành động cố định ở đáy.
    renderDialog();

    expect(blockOf(screen.getByRole("button", { name: /P400/ })).className)
      .toContain("border-t");
  });
});
