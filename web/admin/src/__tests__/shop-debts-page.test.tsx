/**
 * Mặt công nợ cho quản lý (#1998).
 *
 * Mỗi test dưới đây ghim MỘT CÁCH TRANG NÀY CÓ THỂ NÓI DỐI về tiền — không ghim
 * "trang render được". Trang render được là điều kiện cần rẻ tiền; những cách
 * dưới đây mới là thứ khiến người quản lý quyết định sai.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

vi.mock("next/navigation", () => ({
  useParams: () => ({ shopSlug: "test-shop" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/shop/test-shop/debts",
}));

let respond: (url: string) => unknown = () => ({ data: [] });

vi.mock("@/lib/api", () => ({
  apiFetch: vi.fn().mockImplementation((url: string) => {
    try {
      return Promise.resolve(respond(url));
    } catch (error) {
      return Promise.reject(error);
    }
  }),
}));

import { AppProvider } from "@/providers/app-provider";
import ShopDebtsPage from "@/app/shop/[shopSlug]/debts/page";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

const PART_PAID = {
  customer_id: "c-1",
  customer_name: "Tanaka",
  customer_phone: "090-0000-0000",
  customer_tax_code: null,
  order_count: 2,
  total_unpaid: "3751",
  oldest_at: "2026-08-01T10:00:00+09:00",
  latest_at: "2026-08-02T10:00:00+09:00",
  orders: [
    {
      order_id: "o-1",
      order_code: "ORD-1",
      total_amount: "1265.00",
      paid_amount: "10.00",
      unpaid_amount: "1255",
      opened_at: "2026-08-01T10:00:00+09:00",
    },
  ],
};

const ON_ACCOUNT = {
  customer_id: "c-2",
  customer_name: "Suzuki",
  customer_phone: null,
  customer_tax_code: null,
  open_debt_total: "5000",
};

beforeEach(() => {
  respond = () => ({ data: [] });
});

describe("#1998 mặt công nợ cho quản lý", () => {
  it("hiện HAI mục tách bạch và KHÔNG cộng chúng lại", async () => {
    // Luật #1990: nợ được cấp có chủ đích ≠ đơn không ai đóng. Nếu một ngày ai
    // đó thêm ô "tổng công nợ" thì 3751 sẽ biến mất trong 8751, và khoản thứ hai
    // lại vô hình đúng như trước #1990.
    respond = (url) =>
      url.includes("part-paid") ? { data: [PART_PAID] } : { data: [ON_ACCOUNT] };

    render(<ShopDebtsPage />, { wrapper });

    await waitFor(() => expect(screen.getByText("Tanaka")).toBeInTheDocument());
    expect(screen.getByText("Suzuki")).toBeInTheDocument();

    // Hai con số đứng riêng; tổng gộp 8,751 KHÔNG được xuất hiện ở đâu cả.
    expect(screen.queryByText(/8[.,]751/)).not.toBeInTheDocument();
  });

  it("KHÔNG hiện trạng thái rỗng như nhau với 'không có nợ' và 'không tải được'", async () => {
    // Trên một mặt tiền, "sạch" và "hỏng" trông giống nhau là cách tệ nhất để
    // nói dối: người quản lý đóng máy tin rằng không ai nợ.
    respond = () => {
      throw new Error("boom");
    };

    render(<ShopDebtsPage />, { wrapper });

    // `common.error` dịch là "Something went wrong" — khớp theo CHUỖI THẬT,
    // không theo chữ "error" mà tôi đoán.
    await waitFor(() =>
      expect(screen.getAllByText(/something went wrong/i).length).toBe(2),
    );
    expect(screen.queryByText(/No customer carries an on-account balance/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/No order was left part-paid/i)).not.toBeInTheDocument();
  });

  it("một mục hỏng KHÔNG kéo mục kia xuống theo", async () => {
    // Hai nguồn độc lập. Gộp lỗi thì một endpoint chết làm mất luôn số liệu
    // đang đúng của endpoint kia.
    respond = (url) => {
      if (url.includes("part-paid")) throw new Error("boom");
      return { data: [ON_ACCOUNT] };
    };

    render(<ShopDebtsPage />, { wrapper });

    await waitFor(() => expect(screen.getByText("Suzuki")).toBeInTheDocument());
  });

  it("mở chi tiết thì hiện ĐÃ TRẢ và TỔNG, không chỉ CÒN THIẾU", async () => {
    // Chỉ hiện "còn thiếu" thì người đọc không biết khách đã trả bao nhiêu — mà
    // đó là thứ quyết định cách đi đòi (trả 10/1265 khác hẳn trả 1255/1265).
    respond = (url) => (url.includes("part-paid") ? { data: [PART_PAID] } : { data: [] });

    render(<ShopDebtsPage />, { wrapper });

    await waitFor(() => expect(screen.getByText("Tanaka")).toBeInTheDocument());
    // `fireEvent` chứ không `user-event`: gói đó không có trong dự án này.
    fireEvent.click(screen.getByRole("button", { name: /detail/i }));

    await waitFor(() => expect(screen.getByText("ORD-1")).toBeInTheDocument());
    const row = screen.getByText("ORD-1").closest("tr");
    expect(row?.textContent).toMatch(/1[.,]265/);
    expect(row?.textContent).toMatch(/1[.,]255/);
  });
});
