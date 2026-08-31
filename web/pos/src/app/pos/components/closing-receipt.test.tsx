/**
 * #2049 — màn ĐÓNG ĐƠN phải rẽ đúng nhánh, và nhánh "đơn treo" phải KHÔNG có
 * đường in.
 *
 * Bài test khẳng định về DOM chứ không về prop: cái phải đúng là "thu ngân
 * không nhìn thấy nút In biên lai / Xuất hoá đơn đỏ", và một bài test đọc prop
 * vẫn xanh khi ai đó vô tình render lại hai nút đó ở chỗ khác trong màn treo.
 */

import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { ClosingReceipt } from "./closing-receipt";
import type { PaymentReceiptState } from "../hooks/use-receipt-flow";

function Wrapper({ children }: { children: ReactNode }) {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={qc}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

function receiptState(overrides: Partial<PaymentReceiptState> = {}): PaymentReceiptState {
  return {
    customer: { name: "Nguyen Van A", phone: "0900000001" },
    receipts: [
      { index: 1, title: "Thanh toan don hang", description: "ORD-1", amount: 80_000 },
    ],
    totalPaid: 80_000,
    tendered: 80_000,
    paidAt: new Date("2026-08-07T10:00:00Z"),
    tabIdToClose: "tab-1",
    orderId: "order-1",
    customerId: "cus-1",
    remaining: 0,
    onHold: false,
    collected: 80_000,
    ...overrides,
  };
}

function renderClosing(receipt: PaymentReceiptState | null) {
  return render(
    <ClosingReceipt
      receipt={receipt}
      shopSlug="shop-a"
      onCreateDebtPayment={vi.fn().mockResolvedValue({ id: "pay-new" })}
      onDebtCharged={vi.fn()}
      onComplete={vi.fn()}
    />,
    { wrapper: Wrapper },
  );
}

/** Nút in — khớp theo cả 3 locale để test không phụ thuộc locale mặc định. */
const PRINT_LABELS = /In bi[êe]n lai|Print receipt|レシート印刷|領収書を印刷|In ho[áa] đơn đỏ|Print red invoice/i;

describe("ClosingReceipt", () => {
  it("không có phiên thu nào thì không vẽ gì", () => {
    const { container } = renderClosing(null);
    expect(container).toBeEmptyDOMElement();
  });

  it("đơn treo: KHÔNG có nút in nào trên màn hình", () => {
    renderClosing(receiptState({ onHold: true, remaining: 120_000, collected: 80_000 }));

    expect(screen.queryByRole("button", { name: PRINT_LABELS })).toBeNull();
  });

  it("đơn treo: vẫn còn đường ghi nợ phần còn lại", () => {
    // Ẩn nút in mà ẩn luôn lối thoát thì thu ngân kẹt: đơn treo không tự hết
    // treo được, phải có ai đó chịu trách nhiệm khoản nợ.
    renderClosing(receiptState({ onHold: true, remaining: 120_000 }));

    expect(
      screen.getByRole("button", { name: /Ghi nợ|Charge remainder|残額をツケに/i }),
    ).toBeTruthy();
  });

  it("đơn treo: hiện số CÒN NỢ, không chỉ số đã thu", () => {
    renderClosing(receiptState({ onHold: true, remaining: 120_000, collected: 80_000 }));

    // Con số thu ngân phải đọc to lên trước khi cho khách đi.
    expect(screen.getByText(/120[.,]000/)).toBeTruthy();
  });

  it("đơn thu đủ: màn biên lai bình thường, nút in CÓ mặt", () => {
    // Hướng ngược lại — một màn treo chặn tất cả cũng "không in sai", nhưng nó
    // làm cả quán mất đường in. Nhánh này chứng minh cổng có mở.
    renderClosing(receiptState({ onHold: false, remaining: 0 }));

    // getAll, không getBy: màn thu-đủ có HAI nút in (biên lai + hoá đơn đỏ), và
    // đó chính là hai nút mà màn treo phải không có nút nào.
    expect(screen.getAllByRole("button", { name: PRINT_LABELS }).length).toBeGreaterThan(0);
  });
});
