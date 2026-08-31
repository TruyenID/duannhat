/**
 * #1806 S3 — màn HQ gom cảnh báo máy trạm: nó có THẬT SỰ vẽ ra không.
 *
 * File `hq-workstation-alerts.test.ts` bên cạnh ghim logic gom. File này ghim
 * thứ khác hẳn, và là thứ epic này đã trả giá đúng hai lần: **code đúng nhưng
 * không bao giờ chạy**. Ở #1807 là một `AlertStore` không ai gọi; ở S2 là một
 * `<AlertPanel />` bị chèn sau một `return` trong `useEffect` — `tsc` xanh,
 * `build` thành công, panel không tồn tại. Không cổng nào bắt được, vì không
 * cổng nào MOUNT trang.
 *
 * Nên ở đây trang được mount thật, và ba thứ được đòi ra màn hình:
 *
 * | Đòi | Vì sao |
 * |---|---|
 * | tên quán | HQ nhìn nhiều quán; cảnh báo không có địa chỉ thì không hành động được |
 * | `count` | một dòng gộp có thể đại diện hàng trăm lần |
 * | `first_seen` | "vừa xảy ra" khác hẳn "kéo dài từ thứ Hai" |
 *
 * Cộng thêm một cặp đối nghịch: **trống và lỗi phải khác nhau trên màn hình**.
 * Vẽ chung một bảng rỗng cho cả hai là mời người trực đóng máy vì tin rằng
 * không có sự cố nào, trong khi thật ra là không tải được.
 */

import { describe, it, expect, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { ReactNode } from "react";

vi.mock("next/navigation", () => ({
  useParams: () => ({ brandSlug: "test-brand" }),
  useRouter: () => ({ push: vi.fn(), replace: vi.fn() }),
  useSearchParams: () => new URLSearchParams(),
  usePathname: () => "/hq/test-brand/notifications/workstation-alerts",
}));

/** Đặt lại ở từng test: apiFetch trả gì cho endpoint audit thông báo. */
let respond: (url: string) => unknown = () => ({
  data: [],
  meta: { current_page: 1, per_page: 100, total: 0, last_page: 1 },
});

vi.mock("@/lib/api", () => {
  class ApiError extends Error {
    status: number;
    body: Record<string, unknown>;
    constructor(status: number, body: Record<string, unknown>) {
      super((body.message as string) || `API Error ${status}`);
      this.status = status;
      this.body = body;
      this.name = "ApiError";
    }
  }

  return {
    ApiError,
    apiFetch: vi.fn().mockImplementation((url: string) => {
      try {
        return Promise.resolve(respond(url));
      } catch (error) {
        return Promise.reject(error);
      }
    }),
  };
});

import { AppProvider } from "@/providers/app-provider";
import HqWorkstationAlertsPage from "@/app/hq/[brandSlug]/notifications/workstation-alerts/page";

function wrapper({ children }: { children: ReactNode }) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return (
    <QueryClientProvider client={queryClient}>
      <AppProvider>{children}</AppProvider>
    </QueryClientProvider>
  );
}

function alertPage(alerts: Array<Record<string, unknown>>) {
  return {
    data: alerts.map((params, i) => ({
      id: `n${i + 1}`,
      type: "workstation.alert",
      template_key: "workstation.alert",
      params,
      priority: params.severity === "critical" ? "urgent" : "high",
      actor: null,
      subject: { type: "Branch", id: `b${i + 1}`, display_name: params.branch_name },
      organization: null,
      aggregation_key: null,
      created_at: "2026-08-05T09:00:00+00:00",
      recipients_summary: { total: 1, seen: 0, read: 0, dismissed: 0 },
    })),
    meta: { current_page: 1, per_page: 100, total: alerts.length, last_page: 1 },
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  respond = () => alertPage([]);
});

describe("HQ workstation alerts page (#1806 S3)", () => {
  it("mounts and renders the page title", async () => {
    render(<HqWorkstationAlertsPage />, { wrapper });

    await waitFor(() => {
      expect(
        screen.getAllByText(/端末アラート|Workstation alerts|Canh bao may tram/).length
      ).toBeGreaterThan(0);
    });
  });

  it("shows branch name, count and first_seen on the row", async () => {
    respond = () =>
      alertPage([
        {
          branch_name: "Shibuya",
          kind: "no_printer",
          subject: "kitchen-1",
          severity: "critical",
          title: "Kitchen printer offline",
          count: 212,
          first_seen_at: "2026-08-05T08:00:00+00:00",
        },
      ]);

    render(<HqWorkstationAlertsPage />, { wrapper });

    await waitFor(() => {
      expect(screen.getByText("Shibuya")).toBeInTheDocument();
    });
    // count — số lần gộp; nếu mất, một dòng "có sự cố" không nói được mức độ.
    expect(screen.getByText("212")).toBeInTheDocument();
    // kind hiện nguyên dạng: registry của nó nằm ở Go, dịch ở đây sẽ trôi.
    expect(screen.getByText("no_printer")).toBeInTheDocument();
    // first_seen — bất kể locale nào cũng phải có ngày 2026-08-05 dưới dạng số.
    const firstSeenCells = screen.getAllByText(/2026/);
    expect(firstSeenCells.length).toBeGreaterThan(0);
  });

  it("groups by branch — one card per shop", async () => {
    respond = () =>
      alertPage([
        {
          branch_name: "Shibuya",
          kind: "no_printer",
          subject: "kitchen-1",
          severity: "critical",
          title: "printer",
          count: 3,
        },
        {
          branch_name: "Namba",
          kind: "cash_retained",
          subject: "till-1",
          severity: "warning",
          title: "cash",
          count: 1,
        },
      ]);

    render(<HqWorkstationAlertsPage />, { wrapper });

    await waitFor(() => {
      expect(document.querySelectorAll('[data-slot="workstation-alert-branch"]')).toHaveLength(2);
    });
    // Quán nặng hơn đứng trước — màn này trả lời "cứu quán nào trước".
    const cards = [...document.querySelectorAll('[data-slot="workstation-alert-branch"]')];
    expect(cards[0].getAttribute("data-branch-id")).toBe("b1");
  });

  it("empty and error do NOT look the same", async () => {
    const empty = render(<HqWorkstationAlertsPage />, { wrapper });
    await waitFor(() => {
      expect(document.querySelector('[data-slot="workstation-alerts-empty"]')).not.toBeNull();
    });
    expect(document.querySelector('[data-slot="workstation-alerts-error"]')).toBeNull();
    empty.unmount();

    respond = () => {
      throw new Error("boom");
    };
    render(<HqWorkstationAlertsPage />, { wrapper });

    await waitFor(() => {
      expect(document.querySelector('[data-slot="workstation-alerts-error"]')).not.toBeNull();
    });
    expect(document.querySelector('[data-slot="workstation-alerts-empty"]')).toBeNull();
  });

  it("says so when the server had more rows than we loaded", async () => {
    respond = () => {
      const page = alertPage([
        {
          branch_name: "Shibuya",
          kind: "no_printer",
          subject: "k1",
          severity: "critical",
          title: "printer",
          count: 1,
        },
      ]);
      // Server nói còn nhiều hơn nhưng chỉ có 1 trang: mô phỏng đúng trạng
      // thái "bị cắt" mà người đọc PHẢI được biết — im lặng ở đây có thể giấu
      // hẳn một quán đang cháy.
      return { ...page, meta: { ...page.meta, total: 900 } };
    };

    render(<HqWorkstationAlertsPage />, { wrapper });

    await waitFor(() => {
      expect(document.querySelector('[data-slot="workstation-alerts-truncated"]')).not.toBeNull();
    });
  });
});
