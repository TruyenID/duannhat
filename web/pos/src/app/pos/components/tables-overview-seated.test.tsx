/**
 * Bàn CÓ NGƯỜI mà CHƯA CÓ ĐƠN phải đọc khác bàn đang phục vụ (#3009).
 *
 * ## Ca thật
 *
 * 本郷店, 18:04 CN 16/08/2026, giữa ca tối. Quán báo *"giờ A8 order thì C1 lại
 * hiện"* — họ đọc màn hình thành "đơn của A-8 hiện sang C-1". Thật ra C-1 là
 * một bàn riêng: khách quét QR, ngồi xuống, chưa gọi món
 * (`CustomerTableSessionService` lật `free → occupied` TRƯỚC khi có đơn).
 *
 * Màn hình gọi cả hai là **"Đang phục vụ"**, nên chúng không phân biệt được.
 *
 * ## Vì sao KHÔNG phải "bỏ trạng thái đó đi"
 *
 * Bàn đó đang có người thật — không xếp khách khác vào được. Mã cũ gọi nó là
 * `isGhostOccupied` ("bàn ma"), và chính cái tên ấy làm người đọc tưởng phải
 * DIỆT trạng thái, nên ba thứ thật sự sai (nhãn, phép đếm, cửa sổ 4 giờ của
 * reaper) không ai đụng tới.
 *
 * File này ghim NHÃN và PHÉP ĐẾM. Cửa sổ 4 giờ là #3010.
 */

import { beforeAll, beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, within } from "@testing-library/react";
import type { ReactNode } from "react";
import { AppProvider } from "@/providers/app-provider";
import { TablesOverview } from "./tables-overview";
import type { CustomerOrder } from "../types";

beforeAll(() => {
  const proto = window.HTMLElement.prototype as unknown as Record<string, unknown>;
  proto.scrollIntoView = vi.fn();
  proto.hasPointerCapture = vi.fn(() => false);
});

beforeEach(() => {
  localStorage.clear();
  localStorage.setItem("pos_locale", "en");
});

function table(over: Record<string, unknown>) {
  return {
    id: `t-${over.name}`,
    name: over.name,
    seat_count: 4,
    status: "free",
    current_order_id: null,
    is_active: true,
    zone: null,
    ...over,
  } as never;
}

/** A-8 đang phục vụ thật; C-1 khách đã ngồi, chưa gọi món. */
const TABLES = [
  table({ name: "A-8", status: "occupied", current_order_id: "o-a8" }),
  table({ name: "C-1", status: "occupied", current_order_id: null }),
  table({ name: "C-2" }),
];

const ORDERS = [
  // #3210 — `total_amount` để ô bàn có tiền thật mà hiện; các bài cũ không đọc
  // trường này nên thêm vào không đổi gì với chúng.
  { id: "o-a8", order_code: "ORD-A8", guest_count: 2, total_amount: 1340 },
] as unknown as CustomerOrder[];

function Wrapper({ children }: { children: ReactNode }) {
  return <AppProvider>{children}</AppProvider>;
}

function renderOverview(tables = TABLES) {
  render(
    <TablesOverview
      tables={tables}
      orders={ORDERS}
      onOpenOrder={vi.fn()}
      onCreateOrder={vi.fn()}
      onChangeStatus={vi.fn()}
    />,
    { wrapper: Wrapper }
  );
}

/** Ô bàn theo tên hiển thị. */
function tile(name: string): HTMLElement {
  const el = screen.getByText(name).closest("li");
  if (!el) throw new Error(`không tìm thấy ô bàn ${name}`);
  return el as HTMLElement;
}

describe("#3009 bàn đã ngồi chưa gọi món", () => {
  it("KHÔNG dùng chung nhãn với bàn đang phục vụ", () => {
    renderOverview();

    // Đây là vế bắt được lỗi thật: trước bản vá cả hai ô cùng đọc "Occupied".
    expect(within(tile("C-1")).getByText(/seated, no order/i)).toBeTruthy();
    expect(within(tile("C-1")).queryByText(/^Occupied$/i)).toBeNull();
  });

  it("bàn CÓ đơn giữ nguyên nhãn cũ", () => {
    renderOverview();

    expect(within(tile("A-8")).getByText(/^Occupied$/i)).toBeTruthy();
    expect(within(tile("A-8")).queryByText(/seated, no order/i)).toBeNull();
  });

  it("thống kê \"đang phục vụ\" chỉ đếm bàn CÓ đơn", () => {
    renderOverview();

    // 3 bàn, 1 đang phục vụ thật, 1 đã ngồi chưa gọi. Trước bản vá là "2 / 3" —
    // và quán đọc con số đó để biết còn mấy bàn nhận khách được.
    expect(screen.getByText("1 / 3")).toBeTruthy();
    // Chuỗi này xuất hiện ở CẢ ô thống kê lẫn nhãn trên ô bàn — đo số lượng
    // thay vì `getByText`, vốn ném khi trúng nhiều phần tử.
    expect(screen.getAllByText(/seated, no order/i).length).toBeGreaterThanOrEqual(2);
  });

  it("không có bàn nào như vậy ⇒ KHÔNG hiện ô thống kê thừa", () => {
    // Rào phải biết IM. Một ô luôn bằng 0 là nhiễu, và nhiễu lặp lại thì người
    // ta thôi đọc cả thanh thống kê.
    renderOverview([
      table({ name: "A-8", status: "occupied", current_order_id: "o-a8" }),
      table({ name: "C-2" }),
    ]);

    expect(screen.queryByText(/seated, no order/i)).toBeNull();
    expect(screen.getByText("1 / 2")).toBeTruthy();
  });

  it("vẫn bấm được để mở đơn — hành vi click KHÔNG đổi", () => {
    renderOverview();

    // Bản vá đổi CHỮ, không đổi việc. Bàn đã ngồi chưa gọi món vẫn là ô mời
    // tạo đơn (#2524), vì đó chính là việc còn phải làm trên nó.
    expect(within(tile("C-1")).getByText(/new order/i)).toBeTruthy();
  });
});

/*
 * #3210 — ô bàn: bàn CÓ khách hiện TIỀN, bàn trống hiện SỐ GHẾ.
 *
 * Yêu cầu gốc (sheet STT 24) là "bỏ số ghế, thêm tổng đơn khách đã gọi". Bản này
 * bỏ số ghế ở bàn ĐANG PHỤC VỤ, giữ ở bàn TRỐNG: số ghế vô dụng khi khách đã
 * ngồi, chứ không vô dụng lúc chọn chỗ cho khách mới.
 *
 * Nếu quán muốn bỏ hẳn ở cả hai trạng thái thì sửa — nhưng đó là một quyết định,
 * và bài này làm nó thành một quyết định NHÌN THẤY được thay vì một mặc định.
 */
describe("#3210 tiền trên ô bàn", () => {
  it("bàn đang phục vụ hiện TỔNG TIỀN, không hiện số ghế", () => {
    renderOverview();

    const t = tile("A-8");
    expect(within(t).getByText(/1[,.]?340|¥/)).toBeTruthy();
    // Số ghế biến mất khỏi ô bàn CÓ khách — đó là nửa "bỏ" của yêu cầu.
    expect(within(t).queryByText("4")).toBeNull();
  });

  it("bàn TRỐNG vẫn hiện số ghế — nó là thứ cần khi xếp chỗ", () => {
    renderOverview();

    const t = tile("C-2");
    expect(within(t).getByText("4")).toBeTruthy();
  });

  it("bàn đã ngồi mà CHƯA gọi món không hiện tiền bịa", () => {
    // C-1 `status=occupied` nhưng `current_order_id=null`. Hiện "¥0" ở đây đọc
    // như "đã gọi rồi mà hết tiền"; không có đơn thì không có số nào để nói.
    renderOverview();

    const t = tile("C-1");
    expect(within(t).queryByText(/¥\s*0(?!\d)/)).toBeNull();
  });
});
