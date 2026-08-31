import { describe, expect, it, vi, beforeEach } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import { type ReactNode } from "react";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import type { UnresolvedOrdersPreview } from "@/services/till-service";

const { unresolvedOrders } = vi.hoisted(() => ({
  unresolvedOrders: vi.fn<() => Promise<{ data: UnresolvedOrdersPreview }>>(),
}));

vi.mock("@/services/till-service", () => ({
  tillService: { unresolvedOrders },
}));

import { UnresolvedOrdersPanel } from "./unresolved-orders-panel";

const t = (k: string, p?: Record<string, string>) => {
  if (p?.count) return `${k}:${p.count}`;
  if (p?.paid && p?.total) return `${k}:${p.paid}/${p.total}`;
  return k;
};

/**
 * Dòng phụ nằm chung một `<span>` với ngày giờ (`2026/08/12 12:00 · …`), nên
 * `getByText` khớp chính xác KHÔNG BAO GIỜ trúng — testing-library so trên
 * text của cả node. Khớp theo chuỗi con, và giới hạn vào `<span>` để không
 * trúng nhầm thẻ cha bao ngoài.
 */
function bySubText(needle: string) {
  return screen.getByText(
    (_content, el) =>
      el?.tagName === "SPAN" && (el.textContent ?? "").includes(needle),
  );
}

function wrap(node: ReactNode) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>{node}</QueryClientProvider>,
  );
}

function preview(
  overrides?: Partial<UnresolvedOrdersPreview>,
): UnresolvedOrdersPreview {
  return {
    previous_session: {
      id: "prev",
      session_code: "S001",
      ended_at: "2026-08-12T12:48:00Z",
    },
    currency_code: "JPY",
    orders: [
      {
        id: "ord-0217",
        order_code: "ORD-2026-0217",
        status: "paying",
        total_amount: 4720,
        paid_amount: 3720,
        outstanding_amount: 1000,
        table_released: false,
        created_at: "2026-08-12T12:00:00Z",
      },
      {
        id: "ord-0191",
        order_code: "ORD-2026-0191",
        status: "checkout",
        total_amount: 700,
        paid_amount: 0,
        outstanding_amount: 700,
        table_released: true,
        created_at: "2026-08-12T03:00:00Z",
      },
    ],
    totals: { count: 2, outstanding_amount: 1700 },
    ...overrides,
  };
}

beforeEach(() => {
  unresolvedOrders.mockReset();
});

describe("UnresolvedOrdersPanel — #2696", () => {
  it("renders stuck bills with outstanding and the orphan-table badge", async () => {
    unresolvedOrders.mockResolvedValue({ data: preview() });
    wrap(
      <UnresolvedOrdersPanel
        shopSlug="shop-1"
        fallbackCurrency="JPY"
        t={t}
      />,
    );

    expect(
      await screen.findByText("shift.open.unresolved.section"),
    ).toBeInTheDocument();
    expect(screen.getByText("ORD-2026-0217")).toBeInTheDocument();
    expect(screen.getByText("ORD-2026-0191")).toBeInTheDocument();
    expect(
      screen.getByText("shift.open.unresolved.table_released"),
    ).toBeInTheDocument();
    expect(screen.getByText("shift.open.unresolved.status.paying")).toBeInTheDocument();
    expect(
      screen.getByText("shift.open.unresolved.status.checkout"),
    ).toBeInTheDocument();
  });

  it("renders nothing when the list is empty — never blocks open", async () => {
    unresolvedOrders.mockResolvedValue({
      data: preview({ orders: [], totals: { count: 0, outstanding_amount: 0 } }),
    });
    const { container } = wrap(
      <UnresolvedOrdersPanel
        shopSlug="shop-1"
        fallbackCurrency="JPY"
        t={t}
      />,
    );

    await waitFor(() => expect(unresolvedOrders).toHaveBeenCalled());
    expect(container.querySelector('[class*="border-rose"]')).toBeNull();
    expect(
      screen.queryByText("shift.open.unresolved.section"),
    ).not.toBeInTheDocument();
  });

  it("keeps the rose alarm when the flag is absent — old backend, no crash", async () => {
    // Bản backend chưa có #2721: `needs_close_only` / `pending_close_count`
    // không tồn tại. Đọc `undefined` trong React KHÔNG ném lỗi, nên nếu nhánh
    // mới suy sai thì panel âm thầm hạ tông một đơn ĐANG THIẾU TIỀN.
    unresolvedOrders.mockResolvedValue({ data: preview() });
    const { container } = wrap(
      <UnresolvedOrdersPanel shopSlug="shop-1" fallbackCurrency="JPY" t={t} />,
    );

    expect(
      await screen.findByText("shift.open.unresolved.section"),
    ).toBeInTheDocument();
    expect(container.innerHTML).toContain("rose");
    expect(
      screen.getAllByText("shift.open.unresolved.outstanding"),
    ).toHaveLength(2);
    expect(
      screen.queryByText("shift.open.unresolved.settled"),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText("shift.open.unresolved.section_close_only"),
    ).not.toBeInTheDocument();
  });

  it("renders nothing on fetch error — fail-open", async () => {
    unresolvedOrders.mockRejectedValue(new Error("network"));
    wrap(
      <UnresolvedOrdersPanel
        shopSlug="shop-1"
        fallbackCurrency="JPY"
        t={t}
      />,
    );

    await waitFor(() => expect(unresolvedOrders).toHaveBeenCalled());
    expect(
      screen.queryByText("shift.open.unresolved.section"),
    ).not.toBeInTheDocument();
  });
});

/**
 * #2738 — "còn treo" ≠ "còn thiếu tiền". Đơn đã thu đủ mà kẹt trạng thái vẫn
 * phải hiện, nhưng tô nó rose-alarm là báo động sai và báo động sai dạy nhân
 * viên bỏ qua báo động thật.
 */
describe("UnresolvedOrdersPanel — needs_close_only (#2738)", () => {
  function closeOnlyPreview(): UnresolvedOrdersPreview {
    return preview({
      orders: [
        {
          id: "ord-0301",
          order_code: "ORD-2026-0301",
          status: "paying",
          total_amount: 4720,
          paid_amount: 4720,
          outstanding_amount: 0,
          needs_close_only: true,
          // Bàn đã nhả: dòng này CŨNG từng tô rose. Bật lên để phép đo
          // "không còn tông báo động" quét được cả badge, không chỉ số tiền.
          table_released: true,
          created_at: "2026-08-12T12:00:00Z",
        },
        {
          id: "ord-0302",
          order_code: "ORD-2026-0302",
          status: "checkout",
          total_amount: 700,
          paid_amount: 700,
          outstanding_amount: 0,
          needs_close_only: true,
          table_released: false,
          created_at: "2026-08-12T03:00:00Z",
        },
      ],
      totals: {
        count: 2,
        outstanding_amount: 0,
        outstanding_count: 0,
        pending_close_count: 2,
      },
    });
  }

  it("drops the rose alarm and says 'chỉ cần đóng đơn' when every bill is paid", async () => {
    unresolvedOrders.mockResolvedValue({ data: closeOnlyPreview() });
    const { container } = wrap(
      <UnresolvedOrdersPanel shopSlug="shop-1" fallbackCurrency="JPY" t={t} />,
    );

    expect(
      await screen.findByText("shift.open.unresolved.section_close_only"),
    ).toBeInTheDocument();
    expect(
      screen.getByText("shift.open.unresolved.description_close_only"),
    ).toBeInTheDocument();
    // Số đơn chờ đóng đến từ `totals.pending_close_count`, hiện ra thật.
    expect(
      screen.getByText("shift.open.unresolved.pending_close_badge:2"),
    ).toBeInTheDocument();
    // Không một mảnh tông báo động nào còn lại — thẻ, header, badge, số tiền.
    expect(container.innerHTML).not.toContain("rose");
    // Và không được đọc như thiếu tiền.
    expect(
      screen.queryByText("shift.open.unresolved.section"),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByText("shift.open.unresolved.outstanding"),
    ).not.toBeInTheDocument();
    expect(screen.getAllByText("shift.open.unresolved.settled")).toHaveLength(2);
    // Đơn vẫn hiện — ai đó vẫn phải bấm đóng nó.
    expect(screen.getByText("ORD-2026-0301")).toBeInTheDocument();
    expect(screen.getByText("ORD-2026-0302")).toBeInTheDocument();
  });

  it("KEEPS the rose alarm when one bill is genuinely short", async () => {
    // Chống-sửa-quá-tay: một đơn thiếu tiền là đủ để cả panel giữ cảnh báo đỏ,
    // kể cả khi đơn còn lại đã thu đủ.
    const mixed = preview({
      orders: [
        {
          id: "ord-0401",
          order_code: "ORD-2026-0401",
          status: "paying",
          total_amount: 4720,
          paid_amount: 3720,
          outstanding_amount: 1000,
          needs_close_only: false,
          table_released: false,
          created_at: "2026-08-12T12:00:00Z",
        },
        {
          id: "ord-0402",
          order_code: "ORD-2026-0402",
          status: "checkout",
          total_amount: 700,
          paid_amount: 700,
          outstanding_amount: 0,
          needs_close_only: true,
          table_released: false,
          created_at: "2026-08-12T03:00:00Z",
        },
      ],
      totals: {
        count: 2,
        outstanding_amount: 1000,
        outstanding_count: 1,
        pending_close_count: 1,
      },
    });
    unresolvedOrders.mockResolvedValue({ data: mixed });
    const { container } = wrap(
      <UnresolvedOrdersPanel shopSlug="shop-1" fallbackCurrency="JPY" t={t} />,
    );

    expect(
      await screen.findByText("shift.open.unresolved.section"),
    ).toBeInTheDocument();
    expect(container.innerHTML).toContain("rose");
    expect(
      screen.getByText("shift.open.unresolved.outstanding"),
    ).toBeInTheDocument();
    expect(
      screen.queryByText("shift.open.unresolved.section_close_only"),
    ).not.toBeInTheDocument();
    // Dòng đã thu đủ trong rổ đỏ vẫn được đọc là đã thu đủ.
    expect(
      screen.getByText("shift.open.unresolved.settled"),
    ).toBeInTheDocument();
  });

  it("renders nothing when the list is empty even with the new totals", async () => {
    unresolvedOrders.mockResolvedValue({
      data: preview({
        orders: [],
        totals: {
          count: 0,
          outstanding_amount: 0,
          outstanding_count: 0,
          pending_close_count: 0,
        },
      }),
    });
    const { container } = wrap(
      <UnresolvedOrdersPanel shopSlug="shop-1" fallbackCurrency="JPY" t={t} />,
    );

    await waitFor(() => expect(unresolvedOrders).toHaveBeenCalled());
    expect(container.innerHTML).toBe("");
    expect(
      screen.queryByText("shift.open.unresolved.section_close_only"),
    ).not.toBeInTheDocument();
  });
});

describe("UnresolvedOrdersPanel — dòng đã thu đủ không trưng ¥0 (#2770)", () => {
  beforeEach(() => {
    unresolvedOrders.mockReset();
  });

  const settledRow = {
    id: "ord-0402",
    order_code: "ORD-2026-0402",
    status: "checkout" as const,
    total_amount: 700,
    paid_amount: 700,
    outstanding_amount: 0,
    needs_close_only: true,
    table_released: false,
    created_at: "2026-08-12T03:00:00Z",
  };

  const shortRow = {
    id: "ord-0401",
    order_code: "ORD-2026-0401",
    status: "paying" as const,
    total_amount: 4720,
    paid_amount: 3720,
    outstanding_amount: 1000,
    needs_close_only: false,
    table_released: false,
    created_at: "2026-08-12T12:00:00Z",
  };

  it("đơn đã thu đủ: KHÔNG có ¥0 ở cột phải, số thật vẫn còn ở dòng phụ", async () => {
    unresolvedOrders.mockResolvedValue({
      data: preview({
        orders: [settledRow],
        totals: {
          count: 1,
          outstanding_amount: 0,
          outstanding_count: 0,
          pending_close_count: 1,
        },
      }),
    });
    wrap(<UnresolvedOrdersPanel shopSlug="shop-1" fallbackCurrency="JPY" t={t} />);

    expect(
      await screen.findByText("shift.open.unresolved.settled"),
    ).toBeInTheDocument();

    // ĐÂY là bug: nhãn "đã thu đủ" đứng ngay trên `¥0`, đọc thành "đã thu 0 đồng"
    // trong khi đơn này thu 700.
    expect(screen.queryByText("¥ 0")).not.toBeInTheDocument();

    // Và không mất thông tin: số thật vẫn hiện ở dòng phụ.
    expect(
      bySubText("shift.open.unresolved.paid_of_total_settled:¥ 700/¥ 700"),
    ).toBeInTheDocument();
  });

  it("đơn còn thiếu tiền: VẪN in outstanding ở cột phải (chống sửa quá tay)", async () => {
    unresolvedOrders.mockResolvedValue({
      data: preview({
        orders: [shortRow],
        totals: {
          count: 1,
          outstanding_amount: 1000,
          outstanding_count: 1,
          pending_close_count: 0,
        },
      }),
    });
    wrap(<UnresolvedOrdersPanel shopSlug="shop-1" fallbackCurrency="JPY" t={t} />);

    expect(
      await screen.findByText("shift.open.unresolved.outstanding"),
    ).toBeInTheDocument();
    expect(screen.getByText("¥ 1,000")).toBeInTheDocument();
  });

  it("rổ TRỘN: dòng thiếu in số, dòng đã thu đủ thì không", async () => {
    unresolvedOrders.mockResolvedValue({
      data: preview({
        orders: [shortRow, settledRow],
        totals: {
          count: 2,
          outstanding_amount: 1000,
          outstanding_count: 1,
          pending_close_count: 1,
        },
      }),
    });
    wrap(<UnresolvedOrdersPanel shopSlug="shop-1" fallbackCurrency="JPY" t={t} />);

    expect(
      await screen.findByText("shift.open.unresolved.outstanding"),
    ).toBeInTheDocument();
    // Một dòng có số, một dòng không — bản vá phải phân biệt theo TỪNG dòng,
    // không theo tông của cả rổ.
    expect(screen.getByText("¥ 1,000")).toBeInTheDocument();
    expect(screen.queryByText("¥ 0")).not.toBeInTheDocument();
  });

  it("dòng phụ của đơn đã thu đủ dùng khoá KHÔNG lặp chữ 'đã thu'", async () => {
    unresolvedOrders.mockResolvedValue({
      data: preview({
        orders: [shortRow, settledRow],
        totals: {
          count: 2,
          outstanding_amount: 1000,
          outstanding_count: 1,
          pending_close_count: 1,
        },
      }),
    });
    wrap(<UnresolvedOrdersPanel shopSlug="shop-1" fallbackCurrency="JPY" t={t} />);

    await screen.findByText("shift.open.unresolved.outstanding");

    // Bản JA của `paid_of_total` kết thúc bằng 入金済, đứng ngay cạnh cột phải
    // cũng nói 入金済 — lặp hai lần liền kề. Dòng settled dùng khoá riêng.
    expect(
      bySubText("shift.open.unresolved.paid_of_total_settled:¥ 700/¥ 700"),
    ).toBeInTheDocument();
    // Dòng còn thiếu vẫn dùng khoá cũ — chữ "đã thu" ở đó là cần thiết.
    expect(
      bySubText("shift.open.unresolved.paid_of_total:¥ 3,720/¥ 4,720"),
    ).toBeInTheDocument();
  });
});
