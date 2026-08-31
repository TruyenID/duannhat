import { describe, expect, it } from "vitest";
import {
  visibleTaxGroups,
  showsMoneyRow,
  roundingAdjustment,
  showsRoundingAdjustment,
  taxSitsInsideSubtotal,
  serviceChargeDisplay,
  itemTaxTotal,
  menuDisplayPrice,
  productRate,
  serviceTaxTotal,
  taxDisplayPrice,
} from "./tax-display";

describe("taxDisplayPrice", () => {
  it("included mode: stored price is 税込; secondary is the extracted 税抜", () => {
    // ¥2000 incl 10% → net = round(2000/1.1) = 1818.
    const d = taxDisplayPrice(2000, 10, true, "JPY");
    expect(d.includeTax).toBe(true);
    expect(d.primary).toBe(2000);
    expect(d.secondary).toBe(1818);
  });

  it("excluded mode: stored price is 税抜; secondary is the added 税込", () => {
    // ¥2000 excl 10% → gross = round(2000*1.1) = 2200.
    const d = taxDisplayPrice(2000, 10, false, "JPY");
    expect(d.includeTax).toBe(false);
    expect(d.primary).toBe(2000);
    expect(d.secondary).toBe(2200);
  });

  it("8% reduced rate rounds to the yen", () => {
    // included: 1080/1.08 = 1000; excluded: 1000*1.08 = 1080.
    expect(taxDisplayPrice(1080, 8, true, "JPY").secondary).toBe(1000);
    expect(taxDisplayPrice(1000, 8, false, "JPY").secondary).toBe(1080);
  });

  it("null / zero rate → both representations equal the stored price", () => {
    const d = taxDisplayPrice(1500, null, true, "JPY");
    expect(d.primary).toBe(1500);
    expect(d.secondary).toBe(1500);
  });

  it("USD rounds the derived amount to cents", () => {
    // 1000 excl 10% → 1100.00 (cent step, no drift on this value).
    const d = taxDisplayPrice(1000, 10, false, "USD");
    expect(d.secondary).toBeCloseTo(1100, 2);
  });
});

describe("menuDisplayPrice", () => {
  it("shows the stored price as-is regardless of the tax toggle (issue #1042)", () => {
    // The toggle only drives the 税込/税抜 label now — never the number.
    expect(menuDisplayPrice(2350, 10, false, "JPY")).toBe(2350);
    expect(menuDisplayPrice(2350, 10, true, "JPY")).toBe(2350);
    expect(menuDisplayPrice(2350, 8, true, "JPY")).toBe(2350);
  });

  it("produces the same number in both toggle modes", () => {
    expect(menuDisplayPrice(2350, 10, true, "JPY")).toBe(
      menuDisplayPrice(2350, 10, false, "JPY"),
    );
  });

  it("is unaffected by rate or currency", () => {
    expect(menuDisplayPrice(1500, null, true, "JPY")).toBe(1500);
    expect(menuDisplayPrice(1000, 10, true, "USD")).toBe(1000);
  });
});

describe("itemTaxTotal", () => {
  it("sums the per-rate tax across the breakdown", () => {
    expect(
      itemTaxTotal([
        { rate: 8, taxable: 1000, tax: 80 },
        { rate: 10, taxable: 500, tax: 50 },
      ]),
    ).toBe(130);
  });

  it("coerces decimal-string tax fields", () => {
    expect(itemTaxTotal([{ tax: "196" }, { tax: "0" }])).toBe(196);
  });

  it("returns 0 for a missing / empty breakdown", () => {
    expect(itemTaxTotal(null)).toBe(0);
    expect(itemTaxTotal(undefined)).toBe(0);
    expect(itemTaxTotal([])).toBe(0);
  });
});

describe("serviceTaxTotal", () => {
  it("is total tax minus the item tax (the service slice)", () => {
    // ORD-2026-4084: tax_amount 206 = item 196 + service 10.
    expect(serviceTaxTotal(206, [{ rate: 10, taxable: 1955, tax: 196 }])).toBe(
      10,
    );
  });

  it("clamps at 0 when a stale payload has item tax above the total", () => {
    expect(serviceTaxTotal(50, [{ tax: 80 }])).toBe(0);
  });

  it("with no breakdown attributes the whole tax to the (caller-gated) slice", () => {
    // Callers gate on breakdown presence; serviceTaxTotal itself just subtracts 0.
    expect(serviceTaxTotal(206, [])).toBe(206);
  });
});

describe("cart 税抜/税込 reconciliation (Fresh Spring Rolls, ORD-2026-4084)", () => {
  // Net-entered order: item net 893 @10% (tax 89), service net 45 @10%
  // (tax 5), tax_amount 94, total 1032. Per the operator's chosen layout the
  // service charge is ALWAYS shown gross (net + its own tax), and the tax line
  // carries the ITEM tax only — the service tax lives inside the service line.
  const order = {
    subtotal: 893,
    service_charge: 45,
    tax_amount: 94,
    total_amount: 1032,
    tax_breakdown: [{ rate: 10, taxable: 893, tax: 89 }],
  };

  it("service charge is shown gross = net + its own tax (both modes)", () => {
    const svcTax = serviceTaxTotal(order.tax_amount, order.tax_breakdown); // 5
    expect(svcTax).toBe(5);
    expect(order.service_charge + svcTax).toBe(50); // 45 + 5
  });

  it("the tax line carries ITEM tax only (service tax is in the service line)", () => {
    // The displayed tax rows come straight from the item breakdown — no fold.
    const itemTax = itemTaxTotal(order.tax_breakdown);
    expect(itemTax).toBe(89); // NOT 94 — service's 5 sits in the service line
  });

  it("税抜 (add-on): net subtotal + gross service + item tax ≡ total", () => {
    const itemTax = itemTaxTotal(order.tax_breakdown); // 89
    const svcTax = serviceTaxTotal(order.tax_amount, order.tax_breakdown); // 5
    const grossService = order.service_charge + svcTax; // 50
    expect(order.subtotal + grossService + itemTax).toBe(order.total_amount); // 893 + 50 + 89 = 1032
  });

  it("税込 (gross): gross subtotal + gross service ≡ total, note = item tax", () => {
    const itemTax = itemTaxTotal(order.tax_breakdown); // 89
    const svcTax = serviceTaxTotal(order.tax_amount, order.tax_breakdown); // 5
    const grossSubtotal = order.subtotal + itemTax; // 982
    const grossService = order.service_charge + svcTax; // 50
    expect(grossSubtotal + grossService).toBe(order.total_amount); // 982 + 50 = 1032
    // The 内税 note shows the item tax (89); the service's tax is inside the ¥50.
    expect(itemTax).toBe(89);
  });

  it("both modes agree on the same grand total (1032)", () => {
    // 税抜: 893 + 50 + 89 ; 税込: 982 + 50 ; both = 1032.
    expect(893 + 50 + 89).toBe(982 + 50);
  });
});

describe("cart 総額表示 reconciliation (Bun Cha, ORD-2026-4084)", () => {
  // Net-entered shop (is_tax_included=false) that displays 税込
  // (prices_include_tax=true). Server order: subtotal 1955 net, tax 206
  // (item 196 + service 10), service 98 net, total 2259, single 10% group.
  const order = {
    subtotal: 1955,
    service_charge: 98,
    tax_amount: 206,
    total_amount: 2259,
    tax_breakdown: [{ rate: 10, taxable: 1955, tax: 196 }],
  };

  it("line shows the stored subtotal as-is; summary derives gross from the group tax", () => {
    // issue #1042 — menuDisplayPrice no longer recomputes; the line row shows
    // the stored subtotal as-is. The cart's own summary path (showGrossSummary)
    // still derives the 税込 subtotal from the authoritative group tax.
    expect(menuDisplayPrice(order.subtotal, 10, true, "JPY")).toBe(order.subtotal);
    const itemTax = itemTaxTotal(order.tax_breakdown);
    expect(order.subtotal + itemTax).toBe(2151);
  });

  it("gross subtotal − discount + gross service ≡ server total", () => {
    const itemTax = itemTaxTotal(order.tax_breakdown);
    const svcTax = serviceTaxTotal(order.tax_amount, order.tax_breakdown);
    const grossSubtotal = order.subtotal + itemTax; // 2151
    const grossService = order.service_charge + svcTax; // 108
    const discount = 0;
    expect(grossSubtotal - discount + grossService).toBe(order.total_amount);
  });
});

describe("productRate (#1099 single-rate)", () => {
  it("returns the product's ONE rate regardless of how the order is consumed", () => {
    expect(productRate({ tax_rate: 10 })).toBe(10);
    expect(productRate({ tax_rate: 8 })).toBe(8);
    expect(productRate({ tax_rate: 0 })).toBe(0);
  });

  it("returns null when nothing resolved (fresh org / stale client)", () => {
    expect(productRate({})).toBeNull();
    expect(productRate({ tax_rate: null })).toBeNull();
    expect(productRate(null)).toBeNull();
    expect(productRate(undefined)).toBeNull();
  });
});

describe("visibleTaxGroups (#2138)", () => {
  it("giữ nhóm 非課税: nền > 0, thuế = 0 — BR-Z-08 bắt buộc có mặt", () => {
    const kept = visibleTaxGroups([
      { rate: 10, taxable: 1000, tax: 100 },
      { rate: 0, taxable: 900, tax: 0 },
    ]);

    expect(kept.map((g) => g.rate)).toEqual([10, 0]);
  });

  it("bỏ nhóm rỗng thật: cả nền lẫn thuế đều 0", () => {
    const kept = visibleTaxGroups([
      { rate: 10, taxable: 1000, tax: 100 },
      { rate: 8, taxable: 0, tax: 0 },
    ]);

    expect(kept.map((g) => g.rate)).toEqual([10]);
  });

  it("giữ nhóm có thuế ÂM — dữ liệu hỏng phải hiện, không được biến mất", () => {
    // Đúng con số mà lỗi cắt cụt chia bill (#2130) sinh ra.
    const kept = visibleTaxGroups([{ rate: 0, taxable: 600, tax: -1 }]);

    expect(kept).toHaveLength(1);
  });

  it("nhận null/undefined mà không nổ", () => {
    expect(visibleTaxGroups(null)).toEqual([]);
    expect(visibleTaxGroups(undefined)).toEqual([]);
  });
});

describe("showsMoneyRow (#2138)", () => {
  it("HIỆN dòng khi số ÂM — đây là ca mà gate `> 0` nuốt mất", () => {
    // -1 là đúng con số lỗi cắt cụt chia bill (#2130) sinh ra trên đơn 0% thuế.
    expect(showsMoneyRow(-1)).toBe(true);
    expect(showsMoneyRow(-170)).toBe(true);
    expect(showsMoneyRow("-1")).toBe(true);
  });

  it("hiện dòng khi số dương", () => {
    expect(showsMoneyRow(1)).toBe(true);
    expect(showsMoneyRow("1000")).toBe(true);
  });

  it("ẩn dòng khi bằng 0 — ba dòng `0` trên mọi phiếu làm chỉ báo mất tác dụng", () => {
    expect(showsMoneyRow(0)).toBe(false);
    expect(showsMoneyRow("0")).toBe(false);
    expect(showsMoneyRow("0.00")).toBe(false);
  });

  it("ẩn dòng khi THIẾU trường — `Number(undefined)` là NaN, và NaN !== 0 là true", () => {
    // Không có `?? 0` thì dòng hiện ra với `formatCurrency(undefined)`.
    expect(showsMoneyRow(undefined)).toBe(false);
    expect(showsMoneyRow(null)).toBe(false);
  });
});

describe("roundingAdjustment — 端数調整", () => {
  /*
   * Bất biến lấy thẳng từ `OrderPricingCalculator::priceGroups`:
   *
   *   総額表示 (is_tax_included=true)  total = round(subtotal − discount + service)
   *   税抜     (is_tax_included=false) total = round(subtotal − discount + service + tax)
   *
   * Ở chế độ 内税 thuế NẰM SẴN trong subtotal, nên nó không phải một số hạng.
   * Bản cũ trừ `tax_amount` trong CẢ HAI chế độ, và dòng "Làm tròn" trên mọi đơn
   * 総額表示 trở thành −tax_amount.
   */

  describe("総額表示 (内税) — thuế đã nằm trong subtotal, không trừ lần nữa", () => {
    it("đơn ¥400 @10% 内税: không có gì để làm tròn, KHÔNG phải −¥36", () => {
      // Đơn thật ORD-2026-3318: Thạch Sương Sáo ¥400, tax_amount 36.00,
      // total 400. Trước bản sửa dòng này hiện −¥36.
      const adj = roundingAdjustment({
        subtotal: "400.00",
        discount_amount: "0.00",
        service_charge: "0.00",
        tax_amount: "36.00",
        total_amount: "400.00",
        is_tax_included: true,
      });

      expect(adj).toBe(0);
      expect(showsRoundingAdjustment(adj)).toBe(false);
    });

    it("đơn ¥360 @10% 内税 với decimals=1: 0, KHÔNG phải −¥32.8", () => {
      // Đơn thật ORD-2026-3317: Coca-Cola ¥360, tax 32.80 (base 327.20).
      const adj = roundingAdjustment({
        subtotal: "360.00",
        discount_amount: "0.00",
        service_charge: "0.00",
        tax_amount: "32.80",
        total_amount: "360.00",
        is_tax_included: true,
      });

      expect(adj).toBe(0);
      expect(showsRoundingAdjustment(adj)).toBe(false);
    });

    it("KHÔNG phụ thuộc hướng làm tròn — đó chính là triệu chứng người dùng thấy", () => {
      // Cùng một giỏ, ba `tax_rounding_mode`. Chỉ `tax_amount` nhúc nhích, và
      // trong 総額表示 nó không được ảnh hưởng tới dòng làm tròn chút nào. Bản cũ
      // trả về −32.7 / −32.8 / −32.7 — ba số khác nhau, không số nào là làm tròn.
      const cart = {
        subtotal: "360.00",
        discount_amount: "0.00",
        service_charge: "0.00",
        total_amount: "360.00",
        is_tax_included: true,
      };

      const floorMode = roundingAdjustment({ ...cart, tax_amount: "32.70" });
      const ceilMode = roundingAdjustment({ ...cart, tax_amount: "32.80" });
      const halfUp = roundingAdjustment({ ...cart, tax_amount: "32.70" });

      expect([floorMode, ceilMode, halfUp]).toEqual([0, 0, 0]);
    });

    it("giảm giá + phí phục vụ vẫn là số hạng; chỉ THUẾ bị loại", () => {
      // subtotal 1000 − giảm 100 + phí 45 (税込, thuế của nó nằm trong) = 945.
      expect(
        roundingAdjustment({
          subtotal: 1000,
          discount_amount: 100,
          service_charge: 45,
          tax_amount: 85.9,
          total_amount: 945,
          is_tax_included: true,
        }),
      ).toBe(0);
    });

    it("vẫn ghi phần dư THẬT khi có — ẩn dòng ≠ bỏ qua phép trừ", () => {
      // Phân bổ giảm giá theo mức có thể ra số lẻ: 1000 − 33.4 = 966.6 → 967.
      const adj = roundingAdjustment({
        subtotal: 1000,
        discount_amount: 33.4,
        service_charge: 0,
        tax_amount: 87.9,
        total_amount: 967,
        is_tax_included: true,
      });

      expect(adj).toBeCloseTo(0.4, 10);
      expect(showsRoundingAdjustment(adj)).toBe(true);
    });
  });

  describe("税抜 (thuế cộng thêm) — thuế LÀ một số hạng", () => {
    it("decimals=2 để thuế mang phần lẻ: ¥99.90 trên nền ¥999 → +¥0.1", () => {
      // total = roundHalfUp(999 + 99.9) = 1099; phần dư 0.1 là thứ dòng này sinh
      // ra để ghi — đây mới là 端数調整 thật của plan-045 option-B.
      const adj = roundingAdjustment({
        subtotal: "999.00",
        discount_amount: "0.00",
        service_charge: "0.00",
        tax_amount: "99.90",
        total_amount: "1099.00",
        is_tax_included: false,
      });

      expect(adj).toBeCloseTo(0.1, 10);
      expect(showsRoundingAdjustment(adj)).toBe(true);
    });

    it("làm tròn XUỐNG cho số ÂM — dấu là chiều, không phải trang trí", () => {
      // total = roundHalfUp(1000 + 93.4) = 1093; 1093 − 1093.4 = −0.4.
      const adj = roundingAdjustment({
        subtotal: 1000,
        discount_amount: 0,
        service_charge: 0,
        tax_amount: 93.4,
        total_amount: 1093,
        is_tax_included: false,
      });

      expect(adj).toBeCloseTo(-0.4, 10);
      expect(showsRoundingAdjustment(adj)).toBe(true);
    });

    it("thuế nguyên đồng → không dư, ẩn dòng", () => {
      expect(
        roundingAdjustment({
          subtotal: 1000,
          discount_amount: 0,
          service_charge: 50,
          tax_amount: 105,
          total_amount: 1155,
          is_tax_included: false,
        }),
      ).toBe(0);
    });
  });

  describe("đầu vào khuyết", () => {
    it("thiếu discount/service/tax = 0, không phải NaN", () => {
      // `Number(undefined)` là NaN và NaN lan ra cả biểu thức; dòng sẽ hiện
      // `+¥NaN` vì `Math.abs(NaN) >= 0.005` là false… nhưng total thì hỏng thật.
      const adj = roundingAdjustment({
        subtotal: 500,
        total_amount: 500,
        is_tax_included: true,
      });

      expect(adj).toBe(0);
      expect(Number.isNaN(adj)).toBe(false);
    });

    it("is_tax_included KHUYẾT được coi là 税抜 — đúng ngữ nghĩa `?: boolean` của types.ts", () => {
      // Đơn cũ/không đóng dấu: engine của chúng cộng thuế lên trên, nên nhánh
      // 税抜 mới là mặc định an toàn.
      expect(
        roundingAdjustment({
          subtotal: 1000,
          tax_amount: 100,
          total_amount: 1100,
        }),
      ).toBe(0);
    });

    it("chuỗi có dấu thập phân được phân giải (API trả decimal dạng chuỗi)", () => {
      expect(
        roundingAdjustment({
          subtotal: "1000.00",
          discount_amount: "0.00",
          service_charge: "0.00",
          tax_amount: "93.50",
          total_amount: "1094.00",
          is_tax_included: false,
        }),
      ).toBeCloseTo(0.5, 10);
    });
  });

  describe("showsRoundingAdjustment", () => {
    it("ngưỡng là nửa đơn vị NHỎ NHẤT, không phải nửa đơn vị tiền tệ", () => {
      expect(showsRoundingAdjustment(0.005)).toBe(true);
      expect(showsRoundingAdjustment(-0.005)).toBe(true);
      expect(showsRoundingAdjustment(0.004)).toBe(false);
      expect(showsRoundingAdjustment(0)).toBe(false);
    });
  });
});

describe("taxSitsInsideSubtotal", () => {
  it("TRUE khi snapshot đã là gross — kể cả khi không phải quy đổi gì", () => {
    // Đây là ca `showGrossSummary` cố tình loại ra, và là ca làm cột số sai.
    expect(taxSitsInsideSubtotal(true, false)).toBe(true);
  });

  it("TRUE khi giao diện tự quy đổi net → gross", () => {
    expect(taxSitsInsideSubtotal(false, true)).toBe(true);
  });

  it("FALSE chỉ khi hiện 税抜 trên một snapshot net — lúc đó thuế MỚI là số hạng", () => {
    expect(taxSitsInsideSubtotal(false, false)).toBe(false);
    expect(taxSitsInsideSubtotal(null, false)).toBe(false);
    expect(taxSitsInsideSubtotal(undefined, false)).toBe(false);
  });
});

describe("serviceChargeDisplay", () => {
  it("総額表示: service_charge ĐÃ gồm thuế — không cộng thêm lần nữa", () => {
    // ORD-2026-3152: engine ra serviceCharge=58 (税込), serviceChargeTax=5.
    // Giỏ từng hiện ¥63 trong khi tổng đơn chỉ mang ¥58.
    expect(serviceChargeDisplay("58.00", 5, true)).toEqual({ gross: 58, net: 53 });
  });

  it("税抜: service_charge là nền, thuế cộng lên", () => {
    // ORD-2026-4084: net 45 + thuế 5 = 50 税込.
    expect(serviceChargeDisplay(45, 5, false)).toEqual({ gross: 50, net: 45 });
  });

  it("không có thuế phí phục vụ → gross ≡ net ở cả hai chế độ", () => {
    expect(serviceChargeDisplay(60, 0, true)).toEqual({ gross: 60, net: 60 });
    expect(serviceChargeDisplay(60, 0, false)).toEqual({ gross: 60, net: 60 });
  });

  it("thiếu trường = 0, không phải NaN", () => {
    expect(serviceChargeDisplay(null, 0, false)).toEqual({ gross: 0, net: 0 });
    expect(serviceChargeDisplay(undefined, 0, true)).toEqual({ gross: 0, net: 0 });
  });
});

describe("cột số của giỏ PHẢI cộng ra đúng tổng — 内税 snapshot (ORD-2026-3152)", () => {
  /*
   * Đơn thật: Bánh mì thịt viên ¥1.150 (内税 10%) + phí phục vụ 5%.
   * Engine (`priceGroups(["10"=>1150], 0, 5, 10, included=true, …)`):
   *
   *   subtotal 1150 · service 58 (税込, thuế ¥5 nằm trong) · tax_amount 110
   *   total = sumNet + serviceCharge = 1150 + 58 = 1208
   *
   * Giỏ từng vẽ: 1150 + 105 (thuế, CỘNG THÊM) + 63 (phí bị thổi) = 1318,
   * rồi bù bằng "Làm tròn −¥110" cho khớp 1208. Ba dòng sai một lúc.
   */
  const order = {
    subtotal: "1150.00",
    discount_amount: "0.00",
    service_charge: "58.00",
    tax_amount: "110.00",
    total_amount: "1208.00",
    is_tax_included: true,
    tax_breakdown: [{ rate: 10, taxable: 1045, tax: 105 }],
  };

  const itemTax = itemTaxTotal(order.tax_breakdown); // 105
  const svcTax = serviceTaxTotal(order.tax_amount, order.tax_breakdown); // 110 − 105 = 5

  it("thuế KHÔNG phải một số hạng — snapshot đã là gross", () => {
    // showGrossSummary = includeTax && hasBreakdown && !is_tax_included = false,
    // nhưng thuế vẫn nằm trong subtotal.
    expect(taxSitsInsideSubtotal(order.is_tax_included, false)).toBe(true);
  });

  it("phí phục vụ hiện ¥58 (thuế ¥5 nằm trong), KHÔNG phải ¥63", () => {
    const svc = serviceChargeDisplay(order.service_charge, svcTax, order.is_tax_included);
    expect(svc).toEqual({ gross: 58, net: 53 });
    // Dòng con phải tự khớp: trước thuế + thuế = số hiện ở cột phải.
    expect(svc.net + svcTax).toBe(svc.gross);
  });

  it("tạm tính + phí ≡ tổng thu, KHÔNG cần dòng làm tròn nào bù", () => {
    const svc = serviceChargeDisplay(order.service_charge, svcTax, order.is_tax_included);
    const discount = Number(order.discount_amount);

    expect(Number(order.subtotal) - discount + svc.gross).toBe(
      Number(order.total_amount),
    );
    expect(roundingAdjustment(order)).toBe(0);
    expect(showsRoundingAdjustment(roundingAdjustment(order))).toBe(false);
  });

  it("thuế ¥105 đi vào ghi chú 内税, không vào cột cộng", () => {
    // Con số vẫn phải hiện ra — thu ngân cần đọc được nó — nhưng ở dạng
    // "trong đó có", không phải một addend thứ tư.
    expect(itemTax).toBe(105);
    expect(itemTax + svcTax).toBe(Number(order.tax_amount));
  });

  it("cách vẽ CŨ lệch đúng ¥110 — con số dòng làm tròn đã hiện", () => {
    // Chứng minh cả ba lỗi là MỘT: 1150 + 105 + 63 = 1318, lệch 110 so với 1208.
    const oldColumn = Number(order.subtotal) + itemTax + (Number(order.service_charge) + svcTax);
    expect(oldColumn).toBe(1318);
    expect(Number(order.total_amount) - oldColumn).toBe(-110);
  });
});
