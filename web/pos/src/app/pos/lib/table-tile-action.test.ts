import { describe, expect, it } from "vitest";

import { tableTileAction } from "./table-tile-action";

describe("tableTileAction (#2524)", () => {
  it("bàn có đơn đang mở → mở chính đơn đó", () => {
    expect(tableTileAction({ status: "occupied", current_order_id: "o-1" }, "o-1")).toEqual({
      kind: "open-order",
      orderId: "o-1",
    });
  });

  it("bàn trống → tạo đơn mới", () => {
    expect(tableTileAction({ status: "free" }, undefined)).toEqual({ kind: "create-order" });
  });

  it("BÀN MA (occupied, không có current_order_id) → tạo đơn mới, KHÔNG phải nút chết", () => {
    // Đây là ca đã đo trên production 本郷店 C-1/A-3/B-3: trước #2524 ô này
    // `disabled` nên nhân viên không mở được đơn từ màn tổng quan.
    expect(tableTileAction({ status: "occupied", current_order_id: null }, undefined)).toEqual({
      kind: "create-order",
    });
  });

  it("có current_order_id nhưng đơn không tra được → KHÔNG tạo đơn mới", () => {
    // Đơn có thật, chỉ là không nằm trong danh sách đang tải. Tạo đơn mới ở đây
    // là dựng đơn thứ hai chồng lên đơn thật của khách.
    expect(tableTileAction({ status: "occupied", current_order_id: "o-9" }, undefined)).toBeNull();
  });

  it("bàn ngừng hoạt động thì không có thao tác nào, kể cả khi đang free", () => {
    expect(tableTileAction({ status: "free", is_active: false }, undefined)).toBeNull();
    expect(
      tableTileAction({ status: "occupied", current_order_id: null, is_active: false }, undefined),
    ).toBeNull();
  });

  it("cleaning / reserved / out_of_service không mời tạo đơn", () => {
    for (const status of ["cleaning", "reserved", "out_of_service"]) {
      expect(tableTileAction({ status }, undefined)).toBeNull();
    }
  });
});
