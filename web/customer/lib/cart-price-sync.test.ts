import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { computePriceSync, unitPriceBasis } from "./cart-price-sync.ts";

const SIZE_OPTION = {
  id: "size",
  name: "Size",
  type: "single",
  required: true,
  variants: [
    { id: "thuong", name: "Thường", price: 0, default: true },
    { id: "lon", name: "Lớn", price: 200 },
  ],
};

function product(overrides: Record<string, unknown> = {}) {
  return {
    price: 1100,
    options: [SIZE_OPTION],
    active_promotion: null,
    ...overrides,
  } as never;
}

const PICK_LARGE = { size: ["lon"] };

describe("computePriceSync — khung giờ ưu đãi đóng", () => {
  it("nâng giá đúng phần chênh, KHÔNG đụng phần topping đã chốt", () => {
    // Backend hạ thẳng product.price khi khung giờ ưu đãi đang phát: ¥800.
    // Đóng cửa sổ ⇒ bản mới về ¥1,100. Dòng giỏ đang là 800 + 200 (size) + 150
    // (topping) = 1150.
    const result = computePriceSync({
      product: product({ price: 800 }),
      fresh: product({ price: 1100 }),
      selections: PICK_LARGE,
      unitPrice: 1150,
    });

    assert.equal(result.status, "adjusted");
    assert.equal(result.delta, 300);
    // 1150 + 300 = 1450 → topping ¥150 vẫn nguyên trong đó.
    assert.equal(result.unitPrice, 1450);
  });

  it("chạy LẦN HAI trên cùng bản mới thì không đổi gì nữa", () => {
    // Đây là ca mà một test hàm-thuần dễ bỏ sót nhất, và cũng là thứ đắt nhất nếu
    // sai: reducer chạy lại mỗi 30-60s theo poll. Nếu chỗ gọi không thay luôn bản
    // chụp `product` thì delta được cộng lại mỗi vòng — ¥800 thành ¥3,800 sau 5
    // phút ngồi ở màn xác nhận. Hàm này idempotent KHI VÀ CHỈ KHI chỗ gọi thay
    // snapshot; test dưới cố định giao kèo đó.
    const fresh = product({ price: 1100 });
    const first = computePriceSync({
      product: product({ price: 800 }),
      fresh,
      selections: PICK_LARGE,
      unitPrice: 1150,
    });

    const second = computePriceSync({
      product: fresh, // ← chỗ gọi đã thay snapshot
      fresh,
      selections: PICK_LARGE,
      unitPrice: first.unitPrice,
    });

    assert.equal(second.status, "unchanged");
    assert.equal(second.unitPrice, first.unitPrice);
  });
});

describe("computePriceSync — Happy Hour", () => {
  it("hết khuyến mãi phần trăm thì nâng lại về giá thường", () => {
    const result = computePriceSync({
      product: product({ active_promotion: { discount_percent: 20 } }),
      fresh: product(),
      selections: {},
      unitPrice: 880, // 1100 − 20%
    });

    assert.equal(result.status, "adjusted");
    assert.equal(result.delta, 220);
    assert.equal(result.unitPrice, 1100);
  });

  it("khuyến mãi vừa BẮT ĐẦU thì hạ giá — backend đã tự hạ, giỏ phải nói thật", () => {
    const result = computePriceSync({
      product: product(),
      fresh: product({ active_promotion: { discount_percent: 20 } }),
      selections: {},
      unitPrice: 1100,
    });

    assert.equal(result.status, "adjusted");
    assert.equal(result.delta, -220);
    assert.equal(result.unitPrice, 880);
  });

  it("đi qua CẢ HAI nguồn giảm giá cùng lúc", () => {
    // Khung giờ ưu đãi hạ product.price, Happy Hour áp phần trăm lên trên.
    assert.equal(unitPriceBasis(product({ price: 800, active_promotion: { discount_percent: 10 } }), {}), 720);
  });
});

describe("computePriceSync — không so được thì chặn, không đoán", () => {
  it("variant đã chọn biến mất khỏi bản mới → unresolvable", () => {
    // Backend bỏ hẳn option value không còn SKU active. Nếu cứ tính tiếp,
    // optionAdjustedPrice im lặng bỏ qua "lon" ⇒ mốc mới thiếu ¥200 ⇒ giỏ HẠ giá
    // đúng lúc backend sẽ tính CAO hơn.
    const result = computePriceSync({
      product: product(),
      fresh: product({ options: [{ ...SIZE_OPTION, variants: [SIZE_OPTION.variants[0]] }] }),
      selections: PICK_LARGE,
      unitPrice: 1300,
    });

    assert.equal(result.status, "unresolvable");
    assert.equal(result.unitPrice, 1300, "không được đổi giá khi chưa so được");
  });

  it("cả nhóm option biến mất → cũng unresolvable", () => {
    const result = computePriceSync({
      product: product(),
      fresh: product({ options: [] }),
      selections: PICK_LARGE,
      unitPrice: 1300,
    });

    assert.equal(result.status, "unresolvable");
  });
});

describe("computePriceSync — giá không đổi", () => {
  it("admin không sửa gì → unchanged, chỗ gọi giữ nguyên reference", () => {
    const result = computePriceSync({
      product: product(),
      fresh: product(),
      selections: PICK_LARGE,
      unitPrice: 1300,
    });

    assert.equal(result.status, "unchanged");
    assert.equal(result.delta, 0);
  });

  it("admin sửa giá giữa ca → cập nhật, KHÔNG chặn", () => {
    // Đây chính là lý do nhánh same-menu ban đầu không dám so giá: sợ chặn hàng
    // loạt giỏ. Phản ứng là cập nhật + báo nên nỗi lo đó không còn.
    const result = computePriceSync({
      product: product(),
      fresh: product({ price: 1200 }),
      selections: {},
      unitPrice: 1100,
    });

    assert.equal(result.status, "adjusted");
    assert.equal(result.unitPrice, 1200);
  });
});
