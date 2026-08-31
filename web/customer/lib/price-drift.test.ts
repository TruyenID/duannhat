import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { driftUpdatesFromError, mapDriftToCartLines, parsePriceDrift } from "./price-drift.ts";

const BODY = {
  message: "Some item prices changed since they were shown.",
  code: "line_unit_price_drift",
  items: [
    { index: "0", product_sku_id: "sku-pho", expected_unit_price: "800", actual_unit_price: "1100", currency: "JPY" },
    { index: "2", product_sku_id: "sku-tra", expected_unit_price: "300", actual_unit_price: "350", currency: "JPY" },
  ],
};

describe("parsePriceDrift", () => {
  it("đọc được mọi dòng lệch, kể cả khi index là chuỗi", () => {
    const rows = parsePriceDrift(BODY);
    assert.equal(rows?.length, 2);
    assert.deepEqual(rows?.[0], {
      index: 0,
      productSkuId: "sku-pho",
      expected: 800,
      actual: 1100,
      currency: "JPY",
    });
    assert.equal(rows?.[1].index, 2);
  });

  it("lỗi khác thì trả null, không nhận nhầm", () => {
    assert.equal(parsePriceDrift({ code: "coupon_expired", items: [] }), null);
    assert.equal(parsePriceDrift({ message: "boom" }), null);
  });

  it("body lạ / rỗng không làm nổ đường xử lỗi", () => {
    assert.equal(parsePriceDrift(null), null);
    assert.equal(parsePriceDrift("500 Internal Server Error"), null);
    assert.equal(parsePriceDrift({ code: "line_unit_price_drift", items: [] }), null);
    assert.equal(
      parsePriceDrift({ code: "line_unit_price_drift", items: [{ index: "x", actual_unit_price: "n/a" }] }),
      null,
    );
  });
});

describe("mapDriftToCartLines", () => {
  it("ghép theo VỊ TRÍ đã gửi, không theo sku", () => {
    // Cùng một SKU có thể nằm trên nhiều dòng giỏ (khác option/topping/ghi chú).
    // Khớp theo sku sẽ sửa nhầm dòng, nên khoá là thứ tự của mảng vừa POST.
    const rows = parsePriceDrift(BODY)!;
    const updates = mapDriftToCartLines(rows, ["line-a", "line-b", "line-c"]);

    assert.deepEqual(updates, [
      { id: "line-a", unitPrice: 1100 },
      { id: "line-c", unitPrice: 350 },
    ]);
  });

  it("index vượt ngoài mảng thì bỏ qua, không dựng dòng ma", () => {
    const rows = parsePriceDrift(BODY)!;
    assert.deepEqual(mapDriftToCartLines(rows, ["line-a"]), [{ id: "line-a", unitPrice: 1100 }]);
  });
});

describe("driftUpdatesFromError", () => {
  it("lỗi trôi giá → danh sách dòng cần sửa", () => {
    assert.deepEqual(driftUpdatesFromError({ body: BODY }, ["line-a", "line-b", "line-c"]), [
      { id: "line-a", unitPrice: 1100 },
      { id: "line-c", unitPrice: 350 },
    ]);
  });

  it("lỗi khác → null, chỗ gọi xử như cũ", () => {
    assert.equal(driftUpdatesFromError({ body: { code: "coupon_expired" } }, ["line-a"]), null);
    assert.equal(driftUpdatesFromError(new Error("network"), ["line-a"]), null);
    assert.equal(driftUpdatesFromError(null, ["line-a"]), null);
  });

  it("không ghép được dòng nào → null chứ không phải mảng rỗng", () => {
    // Mảng rỗng sẽ khiến chỗ gọi tưởng đã xử lý xong rồi nuốt luôn lỗi.
    assert.equal(driftUpdatesFromError({ body: BODY }, []), null);
  });
});
