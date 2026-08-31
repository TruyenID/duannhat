import { describe, expect, it } from "vitest";
import { orderTotals } from "./order-totals";
import type { CustomerOrder } from "../types";

const order = (over: Record<string, unknown>) =>
  ({
    subtotal: "0",
    discount_amount: "0",
    service_charge: "0",
    tax_amount: "0",
    total_amount: "0",
    ...over,
  }) as unknown as CustomerOrder;

/**
 * Bất biến chung của MỌI chế độ: cột số người ta đọc trên màn hình phải cộng ra
 * đúng số tiền thu. Đây là phép kiểm mà cả giỏ hàng lẫn hộp thoại thu tiền đều
 * phải qua, vì cả hai vẽ từ cùng bộ số này.
 *
 * Thuế chỉ vào tổng khi nó CHƯA nằm trong subtotal — đó là toàn bộ nội dung của
 * `taxIsInside`, và là chỗ bản cũ sai.
 */
function renderedColumnSum(t: ReturnType<typeof orderTotals>): number {
  return (
    t.subtotal -
    t.discount +
    t.service.gross +
    (t.taxIsInside ? 0 : t.itemTax) +
    t.rounding
  );
}

describe("orderTotals", () => {
  describe("ảnh chụp 内税 (is_tax_included=true) — ORD-2026-3152", () => {
    // ¥1.150 内税 10% + phí phục vụ 5%. Engine: service 58 (税込, thuế ¥5 trong),
    // tax_amount 110 (item 105 + service 5), total 1.208.
    const o = order({
      subtotal: "1150.00",
      service_charge: "58.00",
      tax_amount: "110.00",
      total_amount: "1208.00",
      is_tax_included: true,
      tax_breakdown: [{ rate: 10, taxable: 1045, tax: 105 }],
    });

    it("thuế nằm TRONG subtotal, và subtotal không bị quy đổi", () => {
      const t = orderTotals(o, true);
      expect(t.taxIsInside).toBe(true);
      expect(t.subtotalWasConverted).toBe(false);
      expect(t.subtotal).toBe(1150);
    });

    it("phí phục vụ ¥58 (trước thuế ¥53) — KHÔNG phải ¥63", () => {
      const t = orderTotals(o, true);
      expect(t.service).toEqual({ gross: 58, net: 53 });
      expect(t.serviceTax).toBe(5);
    });

    it("không có dòng làm tròn, và cột cộng ra đúng ¥1.208", () => {
      const t = orderTotals(o, true);
      expect(t.showRounding).toBe(false);
      expect(renderedColumnSum(t)).toBe(1208);
      expect(renderedColumnSum(t)).toBe(t.total);
    });
  });

  describe("ảnh chụp net + quán hiện 税込 — ORD-2026-4084 (Bún chả)", () => {
    // subtotal 1955 net, item tax 196, service 98 net + 10 thuế, total 2259.
    const o = order({
      subtotal: "1955",
      service_charge: "98",
      tax_amount: "206",
      total_amount: "2259",
      is_tax_included: false,
      tax_breakdown: [{ rate: 10, taxable: 1955, tax: 196 }],
    });

    it("subtotal ĐƯỢC quy đổi net→gross (1955 + 196)", () => {
      const t = orderTotals(o, true);
      expect(t.subtotalWasConverted).toBe(true);
      expect(t.subtotal).toBe(2151);
      expect(t.taxIsInside).toBe(true);
    });

    it("phí phục vụ cộng thuế lên (98 + 10) vì ảnh chụp là net", () => {
      expect(orderTotals(o, true).service).toEqual({ gross: 108, net: 98 });
    });

    it("cột cộng ra đúng ¥2.259", () => {
      const t = orderTotals(o, true);
      expect(renderedColumnSum(t)).toBe(2259);
    });
  });

  describe("ảnh chụp net + quán hiện 税抜 — ORD-2026-4084 (Gỏi cuốn)", () => {
    const o = order({
      subtotal: "893",
      service_charge: "45",
      tax_amount: "94",
      total_amount: "1032",
      is_tax_included: false,
      tax_breakdown: [{ rate: 10, taxable: 893, tax: 89 }],
    });

    it("thuế LÀ số hạng, subtotal giữ nguyên net", () => {
      const t = orderTotals(o, false);
      expect(t.taxIsInside).toBe(false);
      expect(t.subtotalWasConverted).toBe(false);
      expect(t.subtotal).toBe(893);
      expect(t.itemTax).toBe(89);
    });

    it("cột cộng ra đúng ¥1.032", () => {
      expect(renderedColumnSum(orderTotals(o, false))).toBe(1032);
    });
  });

  describe("bẫy `subtotalWasConverted`", () => {
    it("vẫn TRUE khi itemTax = 0 — không suy được bằng phép so subtotal", () => {
      // Đơn 非課税: nền > 0, thuế 0. `subtotal + itemTax === subtotal`, nên một
      // phép so `converted !== stored` sẽ trả FALSE trong lúc quy đổi đang bật,
      // và subtotal sẽ được render sai định dạng.
      const o = order({
        subtotal: "500",
        total_amount: "500",
        is_tax_included: false,
        tax_breakdown: [{ rate: 0, taxable: 500, tax: 0 }],
      });

      const t = orderTotals(o, true);
      expect(t.itemTax).toBe(0);
      expect(t.subtotal).toBe(500);
      expect(t.subtotalWasConverted).toBe(true);
    });
  });

  describe("phí phục vụ ước lượng của màn soạn giảm giá", () => {
    it("override thay chỗ `order.service_charge`", () => {
      const o = order({
        subtotal: "1000",
        service_charge: "50",
        tax_amount: "100",
        total_amount: "1150",
        is_tax_included: false,
        tax_breakdown: [{ rate: 10, taxable: 1000, tax: 100 }],
      });

      expect(orderTotals(o, false).service.gross).toBe(50);
      expect(orderTotals(o, false, 80).service.gross).toBe(80);
    });

    it("override = 0 vẫn được tôn trọng, không rơi về giá trị đã lưu", () => {
      // `?? ` chứ không phải `||`: 0 là một khoản phí hợp lệ (chưa có phí nào).
      const o = order({ subtotal: "1000", service_charge: "50", total_amount: "1050" });
      expect(orderTotals(o, false, 0).service.gross).toBe(0);
    });
  });

  describe("nhóm thuế", () => {
    it("sắp theo mức tăng dần và GIỮ nhóm 0% (Peppol BR-Z-08)", () => {
      const o = order({
        subtotal: "2000",
        tax_amount: "80",
        total_amount: "2080",
        is_tax_included: false,
        tax_breakdown: [
          { rate: 10, taxable: 800, tax: 80 },
          { rate: 0, taxable: 1200, tax: 0 },
        ],
      });

      expect(orderTotals(o, false).taxGroups.map((g) => g.rate)).toEqual([0, 10]);
    });

    it("không có breakdown → serviceTax = 0, không gán nhầm thuế cho phí", () => {
      const o = order({
        subtotal: "1000",
        service_charge: "50",
        tax_amount: "100",
        total_amount: "1150",
      });

      const t = orderTotals(o, true);
      expect(t.hasBreakdown).toBe(false);
      expect(t.serviceTax).toBe(0);
      expect(t.service).toEqual({ gross: 50, net: 50 });
    });
  });
});
