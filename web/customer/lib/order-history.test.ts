import assert from "node:assert/strict";
import { describe, it } from "node:test";

import {
  deriveKitchenFromItems,
  mapOrderStatusUI,
  matchesOrderTab,
  type OrderHistoryItem,
  type OrderHistorySummary,
} from "./order-history.ts";

/**
 * `/orders` (guest) và `/account/orders` (đã đăng nhập) từng có hai bản logic
 * riêng và đã lệch nhau — bản account thậm chí không có khái niệm "chưa thanh
 * toán". Test này ghim bản dùng chung: đơn nào vào tab nào, và đơn nào được
 * hiện nút "Thanh toán" / "Viết đánh giá".
 */

const NOW = Date.parse("2026-08-01T10:00:00Z");

function item(status: string): OrderHistoryItem {
  return {
    id: `it-${status}`,
    name: "Phở bò",
    image_url: null,
    qty: 1,
    status,
  };
}

function order(patch: Partial<OrderHistorySummary> = {}): OrderHistorySummary {
  return {
    id: "o1",
    code: "ORD-2026-4263",
    status: "open",
    items: [item("pending")],
    total: 1000,
    paid: 0,
    currency: "JPY",
    is_fully_paid: false,
    payment_count: 0,
    ...patch,
  };
}

describe("matchesOrderTab", () => {
  it("giấu đơn awaiting_confirmation ở MỌI tab", () => {
    const o = order({ status: "awaiting_confirmation" });
    for (const tab of ["all", "pending", "paid"] as const) {
      assert.equal(matchesOrderTab(o, tab, NOW), false, `tab ${tab}`);
    }
  });

  it("tab 'all' giữ cả đơn đã huỷ", () => {
    assert.equal(matchesOrderTab(order({ status: "cancelled" }), "all", NOW), true);
  });

  it("tab 'pending' loại đơn huỷ, đơn closed và đơn đã trả đủ", () => {
    assert.equal(matchesOrderTab(order({ status: "cancelled" }), "pending", NOW), false);
    assert.equal(matchesOrderTab(order({ status: "closed" }), "pending", NOW), false);
    assert.equal(
      matchesOrderTab(order({ is_fully_paid: true, paid: 1000 }), "pending", NOW),
      false,
    );
  });

  it("tab 'pending' giữ đơn chưa trả còn hạn, bỏ đơn đã quá hạn", () => {
    const stillDue = order({ payment_due_at: "2026-08-01T10:15:00Z" });
    const overdue = order({ payment_due_at: "2026-08-01T09:45:00Z" });
    assert.equal(matchesOrderTab(stillDue, "pending", NOW), true);
    assert.equal(matchesOrderTab(overdue, "pending", NOW), false);
  });

  it("hạn thanh toán đọc từ nowMs được truyền vào, không phải đồng hồ máy", () => {
    const o = order({ payment_due_at: "2026-08-01T10:15:00Z" });
    // Cùng một đơn, chỉ đổi mốc thời gian → đổi kết quả.
    assert.equal(matchesOrderTab(o, "pending", Date.parse("2026-08-01T10:14:00Z")), true);
    assert.equal(matchesOrderTab(o, "pending", Date.parse("2026-08-01T10:16:00Z")), false);
  });

  it("đơn không có hạn nhưng chưa trả đủ vẫn nằm ở 'pending'", () => {
    assert.equal(matchesOrderTab(order({ paid: 400 }), "pending", NOW), true);
  });

  it("tab 'paid' chỉ giữ đơn đã trả đủ và không bị huỷ", () => {
    assert.equal(
      matchesOrderTab(order({ is_fully_paid: true, paid: 1000 }), "paid", NOW),
      true,
    );
    assert.equal(matchesOrderTab(order(), "paid", NOW), false);
    assert.equal(
      matchesOrderTab(
        order({ status: "cancelled", is_fully_paid: true, paid: 1000 }),
        "paid",
        NOW,
      ),
      false,
    );
  });
});

describe("deriveKitchenFromItems", () => {
  it("bỏ qua món đã void, và trả null khi void hết", () => {
    assert.equal(deriveKitchenFromItems([item("voided")]), null);
    assert.equal(
      deriveKitchenFromItems([item("voided"), item("served")]),
      "all-served",
    );
  });

  it("phân biệt preparing / ready / all-served", () => {
    assert.equal(deriveKitchenFromItems([item("pending"), item("ready")]), "preparing");
    assert.equal(deriveKitchenFromItems([item("ready"), item("served")]), "ready");
    assert.equal(deriveKitchenFromItems([item("served")]), "all-served");
    assert.equal(deriveKitchenFromItems([]), null);
    assert.equal(deriveKitchenFromItems(undefined), null);
  });
});

describe("mapOrderStatusUI", () => {
  it("đơn chưa trả tại quầy → 'Chưa thanh toán' + nút Thanh toán", () => {
    const ui = mapOrderStatusUI("open", false, 0, [item("pending")]);
    assert.equal(ui.payment?.labelKey, "statusUnpaid");
    assert.equal(ui.payment?.action, "continue-pay");
    // Pill bếp phải ẩn — đơn chưa trả tiền mà hiện "đang chuẩn bị" là gây hiểu nhầm.
    assert.equal(ui.kitchen, null);
  });

  it("bếp đã giao hết món nhưng CHƯA trả tiền vẫn là 'Chưa thanh toán'", () => {
    const ui = mapOrderStatusUI("open", false, 0, [item("served")]);
    assert.equal(ui.payment?.labelKey, "statusUnpaid");
    assert.equal(ui.payment?.action, "continue-pay");
  });

  it("đã trả đủ + giao hết → 'Đã hoàn thành' + nút Viết đánh giá", () => {
    const ui = mapOrderStatusUI("closed", true, 1, [item("served")]);
    assert.equal(ui.payment?.labelKey, "statusCompleted");
    assert.equal(ui.payment?.action, "review");
  });

  it("#1758 — đơn ĐÃ đánh giá → 'Đã đánh giá' thay cho nút Viết đánh giá", () => {
    const ui = mapOrderStatusUI("closed", true, 1, [item("served")], true);
    assert.equal(ui.payment?.labelKey, "statusCompleted");
    assert.equal(ui.payment?.action, "reviewed");
  });

  it("#1758 — thiếu cờ is_reviewed (BE cũ) vẫn mời đánh giá, không nuốt nút", () => {
    for (const flag of [undefined, null, false]) {
      const ui = mapOrderStatusUI("closed", true, 1, [item("served")], flag);
      assert.equal(ui.payment?.action, "review", `is_reviewed=${flag}`);
    }
  });

  it("#1758 — đã đánh giá nhưng CHƯA trả đủ vẫn là 'Chưa thanh toán'", () => {
    // Cờ review không được phép cướp quyền của trạng thái thanh toán: đơn chưa
    // trả tiền phải giữ nút "Thanh toán".
    const ui = mapOrderStatusUI("open", false, 0, [item("served")], true);
    assert.equal(ui.payment?.labelKey, "statusUnpaid");
    assert.equal(ui.payment?.action, "continue-pay");
  });

  it("đã trả đủ nhưng món chưa giao xong → 'Đã thanh toán', KHÔNG cho review", () => {
    const ui = mapOrderStatusUI("closed", true, 1, [item("pending")]);
    assert.equal(ui.payment?.labelKey, "statusPaid");
    assert.equal(ui.payment?.action, null);
  });

  it("card flow đang dở (payment_count > 0, chưa trả đủ) → ẩn hẳn dòng dưới", () => {
    const ui = mapOrderStatusUI("paying", false, 1, [item("pending")]);
    assert.equal(ui.payment, null);
  });

  it("cancelled và voided là hai kết cục khác nhau", () => {
    const cancelled = mapOrderStatusUI("cancelled", false, 0, [item("pending")]);
    assert.equal(cancelled.payment?.labelKey, "statusCancelled");
    assert.equal(cancelled.payment?.action, null);

    const voided = mapOrderStatusUI("voided", false, 0, [item("voided")]);
    assert.equal(voided.payment, null);
    assert.equal(voided.kitchen, null);
  });
});
