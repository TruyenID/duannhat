import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { resolveHydrationOrderMode, routeOrderMode } from "./order-mode-route.ts";

describe("routeOrderMode", () => {
  it("nhận route takeaway, có hoặc không có locale prefix", () => {
    assert.equal(routeOrderMode("/takeaway/ningyocho"), "takeaway");
    assert.equal(routeOrderMode("/vi/takeaway/ningyocho"), "takeaway");
    assert.equal(routeOrderMode("/ja/takeaway/ningyocho"), "takeaway");
    assert.equal(routeOrderMode("/en/takeaway/ningyocho"), "takeaway");
  });

  it("nhận route dine-in, có hoặc không có locale prefix", () => {
    assert.equal(routeOrderMode("/dine-in/ningyocho/table/abc123"), "dine_in");
    assert.equal(routeOrderMode("/vi/dine-in/ningyocho/table/abc123"), "dine_in");
    assert.equal(
      routeOrderMode("/ja/dine-in/ningyocho/table/abc123/confirm"),
      "dine_in",
    );
    assert.equal(
      routeOrderMode("/vi/dine-in/ningyocho/table/abc123/payment"),
      "dine_in",
    );
  });

  it("takeaway trần (chưa chọn chi nhánh) vẫn là takeaway", () => {
    assert.equal(routeOrderMode("/takeaway"), "takeaway");
    assert.equal(routeOrderMode("/vi/takeaway"), "takeaway");
  });

  it("trả null cho route dùng chung cả hai chế độ", () => {
    // Đây là câu trả lời THẬT, không phải thất bại: /checkout, /orders,
    // /order-confirm phục vụ cả takeaway lẫn dine-in nên chỉ chế độ đã lưu mới
    // phân biệt được. Caller phải giữ nguyên fallback cho trường hợp này.
    assert.equal(routeOrderMode("/vi/checkout"), null);
    assert.equal(routeOrderMode("/vi/order-confirm/abc123"), null);
    assert.equal(routeOrderMode("/vi/orders/abc123"), null);
    assert.equal(routeOrderMode("/vi/order-success"), null);
  });

  it("trả null cho route không liên quan", () => {
    assert.equal(routeOrderMode("/"), null);
    assert.equal(routeOrderMode("/vi"), null);
    assert.equal(routeOrderMode("/vi/select-branch"), null);
    assert.equal(routeOrderMode("/vi/stores/ningyocho"), null);
    assert.equal(routeOrderMode("/vi/order"), null);
  });

  it("không ăn nhầm khi 'takeaway' nằm sâu trong path", () => {
    // Chỉ segment 0 hoặc 1 mới là marker chế độ. Một chi nhánh tên
    // 'takeaway' hay bài viết tên 'dine-in' không được lật chế độ giỏ hàng.
    assert.equal(routeOrderMode("/vi/stores/takeaway"), null);
    assert.equal(routeOrderMode("/vi/orders/dine-in"), null);
    assert.equal(routeOrderMode("/vi/account/shop/takeaway"), null);
  });

  it("chịu được path rỗng, trailing slash và slash lặp", () => {
    assert.equal(routeOrderMode(""), null);
    assert.equal(routeOrderMode("/"), null);
    assert.equal(routeOrderMode("/vi/takeaway/"), "takeaway");
    assert.equal(routeOrderMode("//vi//takeaway//ningyocho"), "takeaway");
  });
});

describe("resolveHydrationOrderMode", () => {
  it("CHÍNH BUG #1697 — route takeaway thắng, dù tab còn phiên bàn", () => {
    // Đây là trạng thái tái hiện được 100%: tab từng quét QR bàn nên
    // sessionStorage còn bàn và localStorage còn 'dine_in', nhưng khách
    // đang đứng ở /vi/takeaway/ningyocho. Trước đây trả 'dine_in' → món
    // rơi vào giỏ của bàn và /checkout chết ở "Chưa xác định bàn".
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: "takeaway",
        savedOrderType: "dine_in",
        hasRestoredTable: true,
      }),
      "takeaway",
    );
  });

  it("route dine-in + còn bàn → dine_in", () => {
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: "dine_in",
        savedOrderType: "dine_in",
        hasRestoredTable: true,
      }),
      "dine_in",
    );
  });

  it("route dine-in nhưng chế độ đã lưu là takeaway → vẫn dine_in", () => {
    // Xảy ra sau khi khách ghé trang takeaway (làm cờ bị ghi thành
    // 'takeaway') rồi quay lại trang bàn. Route mới là nguồn sự thật.
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: "dine_in",
        savedOrderType: "takeaway",
        hasRestoredTable: true,
      }),
      "dine_in",
    );
  });

  it("route dine-in nhưng KHÔNG còn bàn → takeaway", () => {
    // Tab mới mở bằng link bàn: chưa fetch xong nên chưa có bàn trong
    // sessionStorage. Trả 'dine_in' lúc này thì save effect sẽ key giỏ theo
    // qr_token undefined và ghi đè "[]" lên giỏ takeaway thật của khách.
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: "dine_in",
        savedOrderType: "dine_in",
        hasRestoredTable: false,
      }),
      "takeaway",
    );
  });

  it("route dùng chung (null) → theo chế độ đã lưu", () => {
    // /checkout, /orders/[id], /order-confirm/[id] phục vụ cả hai chế độ.
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: null,
        savedOrderType: "dine_in",
        hasRestoredTable: true,
      }),
      "dine_in",
    );
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: null,
        savedOrderType: "takeaway",
        hasRestoredTable: true,
      }),
      "takeaway",
    );
  });

  it("route dùng chung + đã lưu dine_in nhưng mất bàn → takeaway", () => {
    // Chính là hazard mà comment gốc ở cart-context bảo vệ: phiên dine-in
    // rỗng không được phép ghi đè giỏ takeaway. Fix này giữ nguyên nó.
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: null,
        savedOrderType: "dine_in",
        hasRestoredTable: false,
      }),
      "takeaway",
    );
  });

  it("không có gì trong storage → takeaway", () => {
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: null,
        savedOrderType: null,
        hasRestoredTable: false,
      }),
      "takeaway",
    );
  });

  it("giá trị lạ trong localStorage không lật được chế độ", () => {
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: null,
        savedOrderType: "DINE_IN",
        hasRestoredTable: true,
      }),
      "takeaway",
    );
    assert.equal(
      resolveHydrationOrderMode({
        routeMode: null,
        savedOrderType: "rác",
        hasRestoredTable: true,
      }),
      "takeaway",
    );
  });
});
