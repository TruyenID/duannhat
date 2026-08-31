import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { PosTabBar } from "./pos-tab-bar";
import type { CustomerOrder, TableResource } from "../types";

// Force vi so the provisional placeholder is the Vietnamese label.
beforeEach(() => {
  localStorage.setItem("pos_locale", "vi");
});

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

const base = {
  activeTabId: "t1",
  onSelect: vi.fn(),
  onClose: vi.fn(),
  onCreate: vi.fn(),
  takeawayCount: 0,
};

describe("PosTabBar", () => {
  it("shows the FULL order code on each tab", () => {
    render(
      <PosTabBar
        {...base}
        tabs={[{ tabId: "t1", orderId: "o1", label: "ORD-2026-4219" }]}
        getOrder={() => undefined}
      />,
      { wrapper: Wrapper },
    );
    expect(screen.getByText("ORD-2026-4219")).toBeTruthy();
  });

  it("prefers the live cached order code over the stored label", () => {
    render(
      <PosTabBar
        {...base}
        tabs={[{ tabId: "t1", orderId: "o1", label: "stale" }]}
        getOrder={() => ({ order_code: "ORD-2026-9999" }) as CustomerOrder}
      />,
      { wrapper: Wrapper },
    );
    expect(screen.getByText("ORD-2026-9999")).toBeTruthy();
  });

  it("shows the 'assigning code' placeholder for an empty / provisional code", () => {
    render(
      <PosTabBar
        {...base}
        tabs={[
          { tabId: "t1", orderId: "o1", label: "" },
          { tabId: "t2", orderId: "o2", label: "WS-A1B2-20260625-014" },
        ]}
        getOrder={() => undefined}
      />,
      { wrapper: Wrapper },
    );
    expect(screen.getAllByText("Đang cấp mã…")).toHaveLength(2);
  });

  it("selects a tab on a plain click", () => {
    const onSelect = vi.fn();
    render(
      <PosTabBar
        {...base}
        onSelect={onSelect}
        tabs={[{ tabId: "t1", orderId: "o1", label: "ORD-2026-1" }]}
        getOrder={() => undefined}
      />,
      { wrapper: Wrapper },
    );
    fireEvent.click(screen.getByText("ORD-2026-1"));
    expect(onSelect).toHaveBeenCalledWith("t1");
  });

  it("suppresses the tab click after a drag-pan (never switches order mid-drag)", () => {
    const onSelect = vi.fn();
    const { container } = render(
      <PosTabBar
        {...base}
        onSelect={onSelect}
        tabs={[{ tabId: "t1", orderId: "o1", label: "ORD-2026-1" }]}
        getOrder={() => undefined}
      />,
      { wrapper: Wrapper },
    );
    const strip = container.querySelector(
      '[data-slot="pos-tab-bar"]',
    )!.firstElementChild as HTMLElement;

    // Mouse drag past the threshold, then the terminating click on the tab.
    fireEvent.pointerDown(strip, { pointerType: "mouse", button: 0, clientX: 200 });
    fireEvent.pointerMove(strip, { clientX: 240 });
    fireEvent.pointerUp(strip, { clientX: 240 });
    fireEvent.click(screen.getByText("ORD-2026-1"));

    expect(onSelect).not.toHaveBeenCalled();
  });
});

/*
 * Nhãn tab: BÀN nếu đơn có bàn, MÃ ĐƠN nếu không — và mã đơn mang màu riêng.
 *
 * Thu ngân nghĩ theo bàn ("bàn A-2 gọi thêm gì chưa?"); một dải toàn
 * `ORD-2026-32xx` bắt họ dịch ngược từ mã sang bàn ở mỗi lần liếc mắt. Luật
 * thuần nằm ở `lib/tab-label.ts`; ở đây ghim phần VẼ RA.
 */
const tableRow = (over: Partial<TableResource>): TableResource =>
  ({
    id: "t",
    code: "A-1",
    name: null,
    seat_count: 4,
    status: "occupied",
    is_active: true,
    qr_token: "tok",
    current_order_id: null,
    created_at: null,
    updated_at: null,
    deleted_at: null,
    ...over,
  }) as TableResource;

describe("PosTabBar — nhãn theo bàn", () => {
  it("đơn có bàn hiện TÊN BÀN thay cho mã đơn", () => {
    render(
      <PosTabBar
        {...base}
        tabs={[{ tabId: "t1", orderId: "o1", label: "ORD-2026-3251" }]}
        getOrder={() =>
          ({ order_code: "ORD-2026-3251", tables: [{ code: "A-2" }] }) as CustomerOrder
        }
      />,
      { wrapper: Wrapper },
    );

    expect(screen.getByText("A-2")).toBeTruthy();
    expect(screen.queryByText("ORD-2026-3251")).toBeNull();
  });

  it("lấy được bàn từ FEED khi chi tiết đơn chưa vào cache", () => {
    // Ca sau mỗi lần tải lại trang: tab sống trong localStorage, chi tiết thì
    // chưa. `GET /pos/orders` không trả `tables`, nên feed bàn là nguồn duy nhất.
    render(
      <PosTabBar
        {...base}
        tabs={[{ tabId: "t1", orderId: "o1", label: "ORD-2026-3251" }]}
        getOrder={() => undefined}
        tables={[tableRow({ code: "A-2", current_order_id: "o1" })]}
      />,
      { wrapper: Wrapper },
    );

    expect(screen.getByText("A-2")).toBeTruthy();
  });

  it("đơn KHÔNG có bàn giữ mã đơn và mang màu riêng", () => {
    render(
      <PosTabBar
        {...base}
        tabs={[
          { tabId: "t1", orderId: "o1", label: "ORD-2026-3251" },
          { tabId: "t2", orderId: "o2", label: "ORD-2026-3252" },
        ]}
        getOrder={(id) =>
          id === "o1"
            ? ({ order_code: "ORD-2026-3251", tables: [{ code: "A-2" }] } as CustomerOrder)
            : ({ order_code: "ORD-2026-3252", tables: [] } as unknown as CustomerOrder)
        }
      />,
      { wrapper: Wrapper },
    );

    const tableLabel = screen.getByText("A-2");
    const codeLabel = screen.getByText("ORD-2026-3252");

    expect(tableLabel.getAttribute("data-tab-label")).toBe("table");
    expect(codeLabel.getAttribute("data-tab-label")).toBe("code");
    // Màu chỉ đặt lên nhãn KHÔNG có bàn; nhãn bàn giữ màu trung tính của tab.
    expect(codeLabel.className).toContain("text-primary");
    expect(tableLabel.className).not.toContain("text-primary");
  });

  it("mã đơn KHÔNG biến mất — nó ở tooltip khi tab hiện tên bàn", () => {
    // Hiện bàn mà nuốt luôn mã là lấy đi đường đối chiếu với phiếu in.
    render(
      <PosTabBar
        {...base}
        tabs={[{ tabId: "t1", orderId: "o1", label: "ORD-2026-3251" }]}
        getOrder={() =>
          ({ order_code: "ORD-2026-3251", tables: [{ code: "A-2" }] }) as CustomerOrder
        }
      />,
      { wrapper: Wrapper },
    );

    expect(screen.getByText("A-2").closest("button")!.getAttribute("title"))
      .toBe("A-2 · ORD-2026-3251");
  });

  it("đơn gộp bàn hiện đủ các bàn", () => {
    render(
      <PosTabBar
        {...base}
        tabs={[{ tabId: "t1", orderId: "o1", label: "ORD-2026-3251" }]}
        getOrder={() => undefined}
        tables={[
          tableRow({ id: "x2", code: "A-2", current_order_id: "o1" }),
          tableRow({ id: "x1", code: "A-1", current_order_id: "o1" }),
        ]}
      />,
      { wrapper: Wrapper },
    );

    expect(screen.getByText("A-1 + A-2")).toBeTruthy();
  });

  it("chỗ chờ 'đang cấp mã' cũng mang màu của nhánh không-bàn", () => {
    render(
      <PosTabBar
        {...base}
        tabs={[{ tabId: "t1", orderId: "o1", label: "WS-A1B2-20260625-014" }]}
        getOrder={() => undefined}
      />,
      { wrapper: Wrapper },
    );

    const label = screen.getByText("Đang cấp mã…");
    expect(label.getAttribute("data-tab-label")).toBe("pending");
    expect(label.className).toContain("text-primary");
  });
});
