/**
 * #1674 — tab tỉ lệ tích điểm ở cấp CỬA HÀNG (kế thừa brand hoặc đặt riêng).
 *
 * Ca chịu lực ở đây là **kế thừa**, không phải "gõ số rồi lưu". Một màn hình
 * kế thừa mà chỉ hiện ô trống thì người quản lý không biết khách của mình tích
 * được bao nhiêu điểm — nên test ghim rằng giá trị đang có hiệu lực luôn hiện
 * ra, cả khi nó đến từ brand lẫn khi cả hai tầng đều trống (mặc định hệ thống).
 *
 * Phần còn lại giống tab brand: cặp phải gửi nguyên vẹn, nửa cặp không lưu
 * được, tỉ lệ 0 không lưu được.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, fireEvent, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

vi.mock("next/navigation", () => ({
  useParams: () => ({ shopSlug: "test-shop" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/shop/test-shop/settings",
}));

vi.mock("sonner", () => ({
  toast: { success: vi.fn(), error: vi.fn() },
}));

interface BranchSettings {
  point_earn_amount: number | null;
  point_earn_points: number | null;
  hq_brand_point_earn_amount: number | null;
  hq_brand_point_earn_points: number | null;
  effective_point_earn_amount: number | null;
  effective_point_earn_points: number | null;
}

const NOTHING_SET: BranchSettings = {
  point_earn_amount: null,
  point_earn_points: null,
  hq_brand_point_earn_amount: null,
  hq_brand_point_earn_points: null,
  effective_point_earn_amount: null,
  effective_point_earn_points: null,
};

let settings: BranchSettings = { ...NOTHING_SET };
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
      if (url.includes("/settings/branch") && init?.method === "PATCH") {
        patches.push(JSON.parse(String(init.body)) as Record<string, unknown>);
      }
      return Promise.resolve({ data: settings });
    }),
  };
});

import { AppProvider } from "@/providers/app-provider";
import { PointEarnTab } from "@/app/shop/[shopSlug]/settings/components/point-earn-tab";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider defaultLocale="en">{children}</AppProvider>
    </QueryClientProvider>
  );
}

beforeEach(() => {
  vi.clearAllMocks();
  patches.length = 0;
  settings = { ...NOTHING_SET };
});

describe("Shop point-earn rate (#1674)", () => {
  it("mặc định là kế thừa, và nói rõ tỉ lệ đang chạy đến từ brand", async () => {
    settings = {
      ...NOTHING_SET,
      hq_brand_point_earn_amount: 100,
      hq_brand_point_earn_points: 2,
      effective_point_earn_amount: 100,
      effective_point_earn_points: 2,
    };

    render(<PointEarnTab shopSlug="test-shop" />, { wrapper });

    const inherit = await screen.findByLabelText(/Inherit from the brand/i);
    expect(inherit.getAttribute("data-state")).toBe("checked");
    // Nhãn của chính lựa chọn "kế thừa" phải nói tỉ lệ nó kế thừa là gì.
    expect(screen.getByText(/Inherit from the brand \(100 = 2 points\)/i)).toBeTruthy();
    expect(screen.getByText(/Currently in effect: 100 = 2 points/i)).toBeTruthy();
  });

  it("cả hai tầng trống thì nói là đang chạy mặc định hệ thống, không để trống trơn", async () => {
    render(<PointEarnTab shopSlug="test-shop" />, { wrapper });

    await screen.findByLabelText(/Inherit from the brand/i);
    expect(screen.getByText(/Currently in effect: the system default/i)).toBeTruthy();
  });

  it("ghi đè của cửa hàng hiện lên hai ô", async () => {
    settings = {
      ...NOTHING_SET,
      point_earn_amount: 500,
      point_earn_points: 1,
      hq_brand_point_earn_amount: 100,
      hq_brand_point_earn_points: 2,
      effective_point_earn_amount: 500,
      effective_point_earn_points: 1,
    };

    render(<PointEarnTab shopSlug="test-shop" />, { wrapper });

    const amount = await screen.findByLabelText("Amount");
    expect((amount as HTMLInputElement).value).toBe("500");
    expect((screen.getByLabelText("Points") as HTMLInputElement).value).toBe("1");
    expect(screen.getByText(/Currently in effect: 500 = 1 points/i)).toBeTruthy();
  });

  it("nửa cặp đọc về hiển thị như đang kế thừa", async () => {
    settings = { ...NOTHING_SET, point_earn_amount: 500, point_earn_points: null };

    render(<PointEarnTab shopSlug="test-shop" />, { wrapper });

    const inherit = await screen.findByLabelText(/Inherit from the brand/i);
    expect(inherit.getAttribute("data-state")).toBe("checked");
    expect(screen.queryByLabelText("Amount")).toBeNull();
  });

  it("gửi CẢ CẶP khi đặt riêng", async () => {
    render(<PointEarnTab shopSlug="test-shop" />, { wrapper });

    fireEvent.click(await screen.findByLabelText(/Set a rate for this shop/i));
    fireEvent.change(screen.getByLabelText("Amount"), { target: { value: "500" } });
    fireEvent.change(screen.getByLabelText("Points"), { target: { value: "3" } });
    fireEvent.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(patches).toHaveLength(1));
    expect(patches[0]).toEqual({ point_earn_amount: 500, point_earn_points: 3 });
  });

  it("gửi CẢ CẶP là null khi quay về kế thừa", async () => {
    settings = {
      ...NOTHING_SET,
      point_earn_amount: 500,
      point_earn_points: 3,
      effective_point_earn_amount: 500,
      effective_point_earn_points: 3,
    };

    render(<PointEarnTab shopSlug="test-shop" />, { wrapper });

    fireEvent.click(await screen.findByLabelText(/Inherit from the brand/i));
    fireEvent.click(screen.getByRole("button", { name: /save/i }));

    await waitFor(() => expect(patches).toHaveLength(1));
    expect(patches[0]).toEqual({ point_earn_amount: null, point_earn_points: null });
  });

  it("chặn lưu khi mới điền một nửa, và chặn tỉ lệ 0", async () => {
    render(<PointEarnTab shopSlug="test-shop" />, { wrapper });

    fireEvent.click(await screen.findByLabelText(/Set a rate for this shop/i));
    fireEvent.change(screen.getByLabelText("Amount"), { target: { value: "500" } });
    expect(screen.getByRole("button", { name: /save/i })).toBeDisabled();

    fireEvent.change(screen.getByLabelText("Points"), { target: { value: "1" } });
    fireEvent.change(screen.getByLabelText("Amount"), { target: { value: "0" } });
    expect(screen.getByText(/Amount must be greater than 0/i)).toBeTruthy();
    expect(screen.getByRole("button", { name: /save/i })).toBeDisabled();
    expect(patches).toHaveLength(0);
  });
});
