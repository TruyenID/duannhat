import { describe, expect, it } from "vitest";
import { orderTotals } from "./order-totals";
import type { CustomerOrder } from "../types";

/**
 * Các cạnh của `orderTotals()` mà bộ test chính chưa với tới.
 *
 * Bộ chính chứng minh ba ảnh chụp THẬT cộng ra đúng tổng. Chỗ chưa ai đứng canh
 * là các đầu vào méo — và cột tiền là chỗ mà một `NaN` không dừng lại ở một ô:
 * nó lan qua mọi phép cộng phía sau và biến cả cột thành `¥NaN`, trong khi máy
 * in vẫn nhả phiếu.
 */

const order = (over: Partial<CustomerOrder>): CustomerOrder =>
  ({
    id: "o1",
    status: "open",
    subtotal: 0,
    total_amount: 0,
    ...over,
  }) as unknown as CustomerOrder;

/** Cột như giao diện vẽ nó: tạm tính − giảm + phí(税込) + thuế(nếu là số hạng) + làm tròn. */
const column = (t: ReturnType<typeof orderTotals>): number =>
  t.subtotal -
  t.discount +
  t.service.gross +
  (t.taxIsInside ? 0 : t.itemTax) +
  t.rounding;

describe("phí phục vụ ƯỚC LƯỢNG ở màn soạn mã giảm giá", () => {
  /*
   * Ở màn này giỏ hiện phí phục vụ do CLIENT ước lượng, còn tổng và dòng làm
   * tròn vẫn đến từ đơn đã lưu. Hai nguồn khác nhau trong cùng một cột.
   *
   * Nếu ước lượng lệch con số server đã chốt, cột không cộng ra tổng nữa — và
   * KHÔNG có dòng nào giải thích phần chênh, vì `roundingAdjustment` đọc
   * `order.service_charge` chứ không đọc ước lượng.
   *
   * Đây đúng là lớp lỗi mà PR này sinh ra để diệt, nên nó đáng được ghim rõ
   * ràng thay vì để ngầm.
   */
  const stored = order({
    subtotal: 1000,
    discount_amount: 0,
    service_charge: 100,
    tax_amount: 0,
    total_amount: 1100,
    is_tax_included: true,
  });

  it("không có override → cột cộng ra đúng tổng", () => {
    expect(column(orderTotals(stored, true))).toBe(1100);
  });

  it("override KHỚP con số đã lưu → vẫn cộng ra đúng tổng", () => {
    expect(column(orderTotals(stored, true, 100))).toBe(1100);
  });

  it("override LỆCH → cột lệch đúng bằng phần chênh, và không dòng nào giải thích", () => {
    // Đây là ảnh chụp hành vi hiện tại, KHÔNG phải lời khen nó. Ghim ở đây để
    // nếu ai đó sửa `roundingAdjustment` nhận luôn override thì bài này đỏ và
    // buộc người sửa phải cố ý — thay vì đổi lặng lẽ một bất biến tiền bạc.
    const t = orderTotals(stored, true, 130);

    expect(t.service.gross).toBe(130);
    expect(t.total).toBe(1100);
    expect(t.rounding).toBe(0); // tính từ ¥100 đã lưu, không phải ¥130 đang hiện
    expect(column(t)).toBe(1130); // lệch +30 so với tổng đang hiện
  });
});

describe("đầu vào méo không được biến cột thành NaN", () => {
  it("chuỗi rỗng ở trường tiền = 0, không phải NaN", () => {
    const t = orderTotals(
      order({
        subtotal: "" as unknown as number,
        discount_amount: "" as unknown as number,
        service_charge: "",
        total_amount: "",
      }),
      false,
    );

    expect(Number.isNaN(column(t))).toBe(false);
    expect(column(t)).toBe(0);
  });

  it("chuỗi rác = 0, không phải NaN — `Number('abc')` là NaN và `NaN || 0` cứu nó", () => {
    const t = orderTotals(
      order({ subtotal: "abc" as unknown as number, total_amount: "1000" }),
      false,
    );

    expect(t.subtotal).toBe(0);
    expect(t.total).toBe(1000);
  });

  it("null ở mọi trường = 0", () => {
    const t = orderTotals(
      order({
        subtotal: null,
        discount_amount: null,
        service_charge: null,
        tax_amount: null,
        total_amount: null,
      }),
      false,
    );

    expect(Number.isNaN(column(t))).toBe(false);
  });

  it("breakdown mang `tax` dạng chuỗi thập phân vẫn cộng đúng", () => {
    const t = orderTotals(
      order({
        subtotal: 1000,
        total_amount: 1093.5,
        tax_amount: "93.5",
        tax_breakdown: [
          { rate: 10, taxable: 935, tax: "93.5" },
        ] as unknown as CustomerOrder["tax_breakdown"],
      }),
      false,
    );

    expect(t.itemTax).toBe(93.5);
    expect(t.serviceTax).toBe(0);
  });
});

describe("`is_tax_included` và `pricesIncludeTax` là HAI thứ — lẫn chúng là lỗi gốc", () => {
  const netSnapshot = order({
    subtotal: 1000,
    total_amount: 1100,
    tax_amount: 100,
    is_tax_included: false,
    tax_breakdown: [
      { rate: 10, taxable: 1000, tax: 100 },
    ] as unknown as CustomerOrder["tax_breakdown"],
  });

  it("ảnh chụp net + quán hiện 税抜 → thuế LÀ số hạng, không quy đổi", () => {
    const t = orderTotals(netSnapshot, false);
    expect(t.taxIsInside).toBe(false);
    expect(t.subtotalWasConverted).toBe(false);
    expect(column(t)).toBe(1100);
  });

  it("ảnh chụp net + quán hiện 税込 → quy đổi subtotal, thuế thôi là số hạng", () => {
    const t = orderTotals(netSnapshot, true);
    expect(t.taxIsInside).toBe(true);
    expect(t.subtotalWasConverted).toBe(true);
    expect(t.subtotal).toBe(1100);
    expect(column(t)).toBe(1100);
  });

  it("ảnh chụp GROSS + quán hiện 税抜 → vẫn KHÔNG được cộng thuế lần nữa", () => {
    // Ca nghịch nhất và chính là ca sinh ra dòng "Làm tròn −¥110": ảnh chụp đã
    // là gross nhưng tuỳ chọn hiển thị của quán lại là 税抜. Tuỳ chọn hiển thị
    // KHÔNG được phép biến thuế thành số hạng — thứ quyết định là ảnh chụp.
    const t = orderTotals(
      order({
        subtotal: 1100,
        total_amount: 1100,
        tax_amount: 100,
        is_tax_included: true,
        tax_breakdown: [
          { rate: 10, taxable: 1000, tax: 100 },
        ] as unknown as CustomerOrder["tax_breakdown"],
      }),
      false,
    );

    expect(t.taxIsInside).toBe(true);
    expect(t.rounding).toBe(0);
    expect(column(t)).toBe(1100);
  });

  it("KHÔNG có breakdown thì không quy đổi, dù quán hiện 税込", () => {
    // `showGrossSummary` đòi có breakdown, vì không có nó thì không biết cộng
    // bao nhiêu. Cộng bừa `tax_amount` sẽ nhận nhầm cả phần thuế của phí phục
    // vụ vào subtotal.
    const t = orderTotals(
      order({ subtotal: 1000, total_amount: 1100, tax_amount: 100 }),
      true,
    );

    expect(t.subtotalWasConverted).toBe(false);
    expect(t.subtotal).toBe(1000);
    expect(t.serviceTax).toBe(0);
  });
});

describe("nhóm thuế", () => {
  it("giữ nhóm 0% và sắp tăng dần, kể cả khi nguồn đảo lộn", () => {
    const t = orderTotals(
      order({
        subtotal: 1000,
        total_amount: 1000,
        tax_breakdown: [
          { rate: 10, taxable: 500, tax: 50 },
          { rate: 0, taxable: 300, tax: 0 },
          { rate: 8, taxable: 200, tax: 16 },
          { rate: 8, taxable: 0, tax: 0 },
        ] as unknown as CustomerOrder["tax_breakdown"],
      }),
      false,
    );

    expect(t.taxGroups.map((g) => Number(g.rate))).toEqual([0, 8, 10]);
  });

  it("KHÔNG làm đổi mảng gốc của đơn khi sắp xếp", () => {
    // `.sort()` sửa tại chỗ. Sắp thẳng trên `order.tax_breakdown` sẽ đổi thứ tự
    // của chính đối tượng trong cache TanStack Query, nên một màn khác đang đọc
    // cùng đơn đó sẽ thấy nhóm nhảy chỗ mà không có render nào giải thích.
    const breakdown = [
      { rate: 10, taxable: 500, tax: 50 },
      { rate: 8, taxable: 200, tax: 16 },
    ] as unknown as CustomerOrder["tax_breakdown"];
    const o = order({ subtotal: 1000, total_amount: 1000, tax_breakdown: breakdown });

    orderTotals(o, false);

    expect((breakdown as { rate: number }[]).map((g) => g.rate)).toEqual([10, 8]);
  });
});
