import { describe, expect, it } from "vitest";

import { isTablePickBlocked } from "./table-pick-eligibility";

const table = (over: Partial<Parameters<typeof isTablePickBlocked>[0]> = {}) => ({
  status: "free",
  current_order_id: null,
  is_active: true,
  ...over,
});

describe("isTablePickBlocked", () => {
  it("bàn trống chọn được", () => {
    expect(isTablePickBlocked(table())).toBe(false);
  });

  /**
   * Bài chính của #2606.
   *
   * Khách quét QR ngồi xuống ⇒ `status='occupied'`, nhưng chưa gọi món nên chưa
   * có đơn. Backend CHO PHÉP gán đơn vào đúng ca này
   * (`validateAndAssignTables`: "free, reserved, OR occupied — customer just
   * occupied but hasn't ordered yet"). pos-web từng chặn, và nhân viên hết
   * đường ngoài việc ép bàn về `free` — thứ để lại `TableSession` mở.
   */
  it("bàn occupied mà CHƯA có đơn vẫn chọn được — gương của cổng backend", () => {
    expect(isTablePickBlocked(table({ status: "occupied" }))).toBe(false);
  });

  it("bàn ĐÃ có đơn thì không — đây mới là `isHeld()`", () => {
    expect(
      isTablePickBlocked(table({ status: "occupied", current_order_id: "ord-1" })),
    ).toBe(true);
  });

  it("đơn giữ bàn chặn kể cả khi status còn là free (replica chưa kịp đồng bộ)", () => {
    expect(isTablePickBlocked(table({ status: "free", current_order_id: "ord-1" }))).toBe(
      true,
    );
  });

  it("reserved chọn được — backend liệt kê nó cùng free và occupied", () => {
    expect(isTablePickBlocked(table({ status: "reserved" }))).toBe(false);
  });

  it.each(["cleaning", "out_of_service"])("%s thì không", (status) => {
    expect(isTablePickBlocked(table({ status }))).toBe(true);
  });

  it("bàn tắt thì không, dù trống", () => {
    expect(isTablePickBlocked(table({ is_active: false }))).toBe(true);
  });

  /**
   * `is_active` khuyết ≠ `false`. Replica LAN của workstation từng thiếu cột
   * này; coi khuyết là "tắt" sẽ khoá sạch lưới bàn ngay khi mất đồng bộ.
   */
  it("is_active khuyết được coi là đang bật", () => {
    const t = table();
    delete (t as { is_active?: boolean }).is_active;
    expect(isTablePickBlocked(t)).toBe(false);
  });

  /**
   * Cùng lý lẽ với fallback `TABLE_STATUS_META[status] ?? free` trong picker:
   * replica LAN từng seed `status='available'`. Trạng thái lạ không được tự
   * khoá bàn — backend mới là nơi từ chối, và nó trả 422 nói rõ lý do.
   */
  it("trạng thái lạ không tự khoá bàn", () => {
    expect(isTablePickBlocked(table({ status: "available" }))).toBe(false);
  });
});
