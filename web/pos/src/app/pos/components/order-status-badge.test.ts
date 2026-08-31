/**
 * #2049 — đơn ĐANG TREO phải đọc ra "Đang treo", không đọc ra "Hoàn thành".
 *
 * Đây là nửa còn lại của quyết định "treo là trạng thái DẪN XUẤT": đơn ghi nợ
 * toàn bộ vẫn mang `status = 'closed'` trong DB (để doanh thu, bàn và Z-report
 * không đổi), nên nếu nhãn không bị đè thì màn hình nói với thu ngân rằng đơn
 * đã xong trong khi quán chưa cầm đồng nào.
 */

import { describe, expect, it } from "vitest";
import { orderStatusBadge } from "./order-history-shared";
import type { CustomerOrder } from "../types";

/** `t` giả: trả về chính khoá, đủ để khẳng định khoá nào được chọn. */
const t = ((key: string) => key) as unknown as Parameters<typeof orderStatusBadge>[1];

function order(overrides: Partial<CustomerOrder>): CustomerOrder {
  return { status: "closed", ...overrides } as CustomerOrder;
}

describe("orderStatusBadge", () => {
  it("đơn treo đọc ra 'Đang treo' dù status trong DB là closed", () => {
    const badge = orderStatusBadge(order({ status: "closed", is_on_hold: true }), t);

    expect(badge.label).toBe("pos.on_hold.status");
  });

  it("đơn treo mang màu hổ phách, không mang màu xám của việc-đã-xong", () => {
    // Xám là màu `closed`. Đơn treo là việc còn dở và phải nhìn giống
    // checkout/paying, nếu không thì nó lẫn vào danh sách đơn đã hoàn thành.
    const badge = orderStatusBadge(order({ status: "closed", is_on_hold: true }), t);

    expect(badge.className).toContain("amber");
    expect(badge.className).not.toContain("muted");
  });

  it("đơn KHÔNG treo giữ nguyên nhãn theo status — kể cả khi cờ là null", () => {
    // `null` = "bề mặt này không trả lời". Đọc nó thành treo sẽ dán nhãn
    // "Đang treo" lên mọi đơn ở mọi màn hình chưa được đóng dấu.
    for (const flag of [false, null, undefined]) {
      const badge = orderStatusBadge(order({ status: "closed", is_on_hold: flag }), t);
      expect(badge.label).toBe("pos.order_status.closed");
    }
  });

  it("status lạ vẫn có màu, không rơi ra undefined className", () => {
    const badge = orderStatusBadge(order({ status: "brand_new" as never }), t);

    expect(badge.className).toBeTruthy();
  });
});
