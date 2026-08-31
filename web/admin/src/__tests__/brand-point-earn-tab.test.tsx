/**
 * #1674 — tab tỉ lệ tích điểm MẶC ĐỊNH của brand ("<số tiền> = <số điểm>").
 *
 * Màn hình này chỉ đặt mặc định nên không có lựa chọn chế độ: hai ô, điền thì
 * đó là mặc định, để trống cả hai thì rơi về mặc định hệ thống. Chuyện "kế
 * thừa hay đặt riêng" thuộc về CỬA HÀNG và được ghim ở
 * `shop-point-earn-tab.test.tsx`.
 *
 * Ca chịu lực ở đây là **cặp**. Backend từ chối nửa cặp bằng 422, nên nếu giao
 * diện lỡ gửi được một nửa thì người dùng chỉ thấy một lỗi đỏ không giải thích
 * được. Test bám vào đó: hai ô cùng gửi, hai ô cùng xoá, và nửa cặp thì không
 * bấm Lưu được kèm lý do đọc được trên màn hình.
 *
 * Cũng ghim chuyện nửa cặp ĐỌC VỀ (dữ liệu cũ, hoặc một lần sửa tay trong DB)
 * hiển thị như chưa đặt — khớp với cách backend tính điểm, thay vì bày ra một
 * ô có số và một ô trống trông như cấu hình hợp lệ.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor, fireEvent } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

vi.mock("next/navigation", () => ({
  useParams: () => ({ brandSlug: "test-brand" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/hq/test-brand/settings/point-earn",
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

interface Settings {
  point_earn_amount: number | null;
  point_earn_points: number | null;
}

let settings: Settings = { point_earn_amount: null, point_earn_points: null };
const patches: Array<Record<string, unknown>> = [];

vi.mock("@/lib/api", () => {
  class ApiError extends Error {
    status: number;
    body: unknown;
    constructor(status: number, body: Record<string, unknown>) {
      super((body.message as string) || `API Error ${status}`);
      this.status = status;
      this.body = body;
      this.name = "ApiError";
    }
  }

  return {
    ApiError,
    apiFetch: vi.fn().mockImplementation((url: string, init?: RequestInit) => {
      if (url.includes("/settings/brand") && init?.method === "PATCH") {
        const body = JSON.parse(String(init.body)) as Record<string, unknown>;
        patches.push(body);
        settings = { ...settings, ...(body as unknown as Settings) };
      }
      return Promise.resolve({ data: settings });
    }),
  };
});

import { AppProvider } from "@/providers/app-provider";
import { BrandPointEarnTab } from "@/app/hq/[brandSlug]/settings/components/brand-point-earn-tab";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      {/* `en` để assertion dưới đây viết bằng tiếng Anh cho gọn. */}
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  patches.length = 0;
  settings = { point_earn_amount: null, point_earn_points: null };
});

describe("Brand default point-earn rate (#1674)", () => {
  it("hiện hai ô ngay, không bắt chọn chế độ nào trước", async () => {
    render(<BrandPointEarnTab brandSlug="test-brand" />, { wrapper });

    const amount = await screen.findByLabelText("Amount");
    expect((amount as HTMLInputElement).value).toBe("");
    expect((screen.getByLabelText("Points") as HTMLInputElement).value).toBe("");
    expect(screen.getByText(/Leave both fields empty/i)).toBeTruthy();
  });

  it("đổ sẵn mặc định đang có và hiện câu xem trước", async () => {
    settings = { point_earn_amount: 100, point_earn_points: 2 };

    render(<BrandPointEarnTab brandSlug="test-brand" />, { wrapper });

    const amount = await screen.findByLabelText("Amount");
    expect((amount as HTMLInputElement).value).toBe("100");
    expect((screen.getByLabelText("Points") as HTMLInputElement).value).toBe("2");
    expect(screen.getByText("100 = 2 points")).toBeTruthy();
  });

  it("coi nửa cặp đọc về là CHƯA đặt", async () => {
    // Backend chặn nửa cặp ở 422, nhưng dữ liệu cũ vẫn có thể như vậy. Bày ra
    // một ô có số và một ô trống sẽ đọc như một tỉ lệ hợp lệ — mà nó không phải.
    settings = { point_earn_amount: 500, point_earn_points: null };

    render(<BrandPointEarnTab brandSlug="test-brand" />, { wrapper });

    const amount = await screen.findByLabelText("Amount");
    expect((amount as HTMLInputElement).value).toBe("");
  });

  it("gửi CẢ CẶP khi lưu mặc định", async () => {
    render(<BrandPointEarnTab brandSlug="test-brand" />, { wrapper });

    fireEvent.change(await screen.findByLabelText("Amount"), { target: { value: "100" } });
    fireEvent.change(screen.getByLabelText("Points"), { target: { value: "2" } });
    fireEvent.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(patches).toHaveLength(1));
    expect(patches[0]).toEqual({ point_earn_amount: 100, point_earn_points: 2 });
  });

  it("xoá cả hai ô = xoá mặc định, gửi CẢ CẶP là null", async () => {
    settings = { point_earn_amount: 100, point_earn_points: 2 };

    render(<BrandPointEarnTab brandSlug="test-brand" />, { wrapper });

    fireEvent.change(await screen.findByLabelText("Amount"), { target: { value: "" } });
    fireEvent.change(screen.getByLabelText("Points"), { target: { value: "" } });
    fireEvent.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(patches).toHaveLength(1));
    expect(patches[0]).toEqual({ point_earn_amount: null, point_earn_points: null });
  });

  it("chặn lưu khi mới điền một nửa, và nói rõ vì sao", async () => {
    render(<BrandPointEarnTab brandSlug="test-brand" />, { wrapper });

    fireEvent.change(await screen.findByLabelText("Amount"), { target: { value: "100" } });

    expect(screen.getByText(/Fill in both fields, or leave both empty/i)).toBeTruthy();
    expect(screen.getByRole("button", { name: /save/i })).toBeDisabled();
    expect(patches).toHaveLength(0);
  });

  it("chặn lưu tỉ lệ 0 — tắt tích điểm không được nguỵ trang thành một tỉ lệ", async () => {
    render(<BrandPointEarnTab brandSlug="test-brand" />, { wrapper });

    fireEvent.change(await screen.findByLabelText("Amount"), { target: { value: "0" } });
    fireEvent.change(screen.getByLabelText("Points"), { target: { value: "1" } });

    expect(screen.getByText(/Amount must be greater than 0/i)).toBeTruthy();
    expect(screen.getByRole("button", { name: /save/i })).toBeDisabled();
  });
});
