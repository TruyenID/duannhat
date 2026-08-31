import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { existsSync } from "node:fs";
import { join } from "node:path";

import {
  CART_SCOPED_SEGMENTS,
  accountHref,
  authEntryPointsAllowed,
  headerAuthSlot,
  loginHref,
  missingShopSegment,
  registerHref,
  selectBranchHref,
  shopScopedHref,
  shopSlugFromPathname,
} from "./shop-routes.ts";

describe("missingShopSegment", () => {
  it("bắt đúng ba khu khi URL không có cửa hàng", () => {
    assert.equal(missingShopSegment("/login"), "/login");
    assert.equal(missingShopSegment("/register"), "/register");
    assert.equal(missingShopSegment("/account"), "/account");
  });

  it("bỏ qua trailing slash — /login/ vẫn là URL thiếu cửa hàng", () => {
    assert.equal(missingShopSegment("/login/"), "/login");
    assert.equal(missingShopSegment("/account/"), "/account");
  });

  it("cho qua khi đã có segment cửa hàng", () => {
    assert.equal(missingShopSegment("/login/shibuya"), null);
    assert.equal(missingShopSegment("/register/shibuya"), null);
    assert.equal(missingShopSegment("/account/shibuya"), null);
    assert.equal(missingShopSegment("/account/shibuya/orders"), null);
  });

  it("không đụng tới các khu khác", () => {
    assert.equal(missingShopSegment("/"), null);
    assert.equal(missingShopSegment("/menus"), null);
    assert.equal(missingShopSegment("/checkout"), null);
    assert.equal(missingShopSegment("/takeaway/shibuya"), null);
  });

  // Chốt lại lý do chỉ khớp CHÍNH XÁC prefix: URL cũ kiểu /account/orders lọt
  // qua middleware với "orders" đóng vai slug, và bị chặn ở tầng trang khi đối
  // chiếu với danh sách chi nhánh. Chặn ở đây bằng danh sách tên route cũ sẽ
  // đẻ ra vòng lặp redirect nếu có chi nhánh trùng tên.
  it("KHÔNG tự đoán URL cũ như /account/orders là thiếu cửa hàng", () => {
    assert.equal(missingShopSegment("/account/orders"), null);
    assert.equal(missingShopSegment("/account/points"), null);
  });

  it("không nhận diện tiền tố trùng một phần", () => {
    assert.equal(missingShopSegment("/logins"), null);
    assert.equal(missingShopSegment("/accounting"), null);
  });
});

describe("href helper", () => {
  it("dựng URL có cửa hàng khi biết slug", () => {
    assert.equal(loginHref("shibuya"), "/login/shibuya");
    assert.equal(registerHref("shibuya"), "/register/shibuya");
    assert.equal(accountHref("shibuya"), "/account/shibuya");
    assert.equal(accountHref("shibuya", "orders"), "/account/shibuya/orders");
    assert.equal(accountHref("shibuya", "orders/42"), "/account/shibuya/orders/42");
  });

  // Không trả về URL trần: middleware sẽ đá nó ra ngay, nên link coi như chết.
  it("rơi về /select-branch khi chưa biết cửa hàng", () => {
    assert.equal(loginHref(undefined), "/select-branch?next=login");
    assert.equal(loginHref(null), "/select-branch?next=login");
    assert.equal(loginHref(""), "/select-branch?next=login");
    assert.equal(registerHref(undefined), "/select-branch?next=register");
    assert.equal(accountHref(undefined), "/select-branch?next=account");
    assert.equal(accountHref(undefined, "orders"), "/select-branch?next=account");
  });

  it("selectBranchHref + shopScopedHref khớp nhau qua vòng chọn cửa hàng", () => {
    assert.equal(selectBranchHref("account"), "/select-branch?next=account");
    assert.equal(shopScopedHref("account", "ginza", "points"), "/account/ginza/points");
    assert.equal(shopScopedHref("login", "ginza"), "/login/ginza");
  });

  it("không sinh dấu / lặp khi sub có sẵn dấu /", () => {
    assert.equal(accountHref("ginza", "/orders/"), "/account/ginza/orders");
  });
});

describe("authEntryPointsAllowed", () => {
  it("cho hiện khi URL mang slug cửa hàng", () => {
    for (const path of [
      "/takeaway/ginza",
      "/dine-in/ginza/table/abc123",
      "/stores/ginza",
      "/login/ginza",
      "/register/ginza",
      "/account/ginza",
      "/account/ginza/orders/42",
    ]) {
      assert.equal(authEntryPointsAllowed(path), true, path);
    }
  });

  // Cửa hàng đến từ giỏ / đơn chứ không từ URL — vẫn quy được về đúng chi
  // nhánh. /checkout là chỗ khách cần đăng nhập để dùng coupon
  // `customer_required`, ẩn ở đây là cắt đường ngay lúc cần nhất.
  it("cho hiện trong luồng mua dù URL trần", () => {
    for (const path of [
      "/checkout",
      "/order-confirm/019f6efa",
      "/order-success",
    ]) {
      assert.equal(authEntryPointsAllowed(path), true, path);
    }
  });

  // #2675 — một segment trỏ tới route KHÔNG TỒN TẠI thì không có gì đỏ.
  //
  // `/checkout-review` bị xoá (route chết, không đường vào nào, và nó POST
  // `payment_method: "counter"` cứng — đúng cái #2545 cấm), nhưng entry của nó
  // trong danh sách này vẫn nằm nguyên và mọi test vẫn xanh: cái set chỉ được
  // đọc bằng phép so chuỗi, nên một tên ma cư trú vĩnh viễn ở đây.
  //
  // Đối chiếu với hệ thống file là phép đo duy nhất phân biệt được "route thật"
  // với "tên còn sót". Chiều PHẢI IM: mọi segment đang có route thì im.
  it("mọi segment trong danh sách đều có route thật dưới app/[locale]/", () => {
    const appDir = join(import.meta.dirname, "..", "app", "[locale]");
    const phantom = [...CART_SCOPED_SEGMENTS].filter(
      (segment) => !existsSync(join(appDir, segment)),
    );

    assert.deepEqual(
      phantom,
      [],
      `Segment không có route tương ứng — xoá khỏi CART_SCOPED_SEGMENTS:\n  ${phantom.join("\n  ")}`,
    );
  });

  it("ẩn trên trang cấp thương hiệu — không có cửa hàng nào để quy về", () => {
    for (const path of [
      "/",
      "/menus",
      "/orders",
      "/orders/42",
      "/select-branch",
      "/menuorder",
      "/about",
      "/concept",
      "/party",
      "/order",
    ]) {
      assert.equal(authEntryPointsAllowed(path), false, path);
    }
  });

  // `/takeaway` trần là trang chọn cửa hàng của luồng takeaway, chưa có chi
  // nhánh nào — đừng nhầm với `/takeaway/{shop}`.
  it("ẩn khi segment mang-cửa-hàng đứng một mình, không có slug theo sau", () => {
    assert.equal(authEntryPointsAllowed("/takeaway"), false);
    assert.equal(authEntryPointsAllowed("/stores"), false);
    assert.equal(authEntryPointsAllowed("/login"), false);
  });

  it("không phụ thuộc locale prefix — quét theo segment, không đếm vị trí", () => {
    assert.equal(authEntryPointsAllowed("/vi/takeaway/ginza"), true);
    assert.equal(authEntryPointsAllowed("/ja/checkout"), true);
    assert.equal(authEntryPointsAllowed("/en/menus"), false);
    assert.equal(authEntryPointsAllowed("/vi"), false);
  });
});

// `UserMenu` dựng href khu tài khoản từ URL trước, `currentBranch`
// (localStorage) chỉ là dự phòng — nên hàm này giờ nằm trên đường đi của chip,
// không chỉ của đường 401 toàn cục.
describe("shopSlugFromPathname", () => {
  it("rút slug từ mọi segment mang-cửa-hàng, kể cả khi còn locale prefix", () => {
    assert.equal(shopSlugFromPathname("/stores/ginza"), "ginza");
    assert.equal(shopSlugFromPathname("/vi/takeaway/ginza"), "ginza");
    assert.equal(shopSlugFromPathname("/ja/dine-in/ginza/table/abc123"), "ginza");
    assert.equal(shopSlugFromPathname("/account/ginza/orders/42"), "ginza");
    assert.equal(shopSlugFromPathname("/login/ginza"), "ginza");
  });

  it("trả null khi URL không mang cửa hàng — chỗ gọi phải tự có dự phòng", () => {
    assert.equal(shopSlugFromPathname("/"), null);
    assert.equal(shopSlugFromPathname("/menus"), null);
    assert.equal(shopSlugFromPathname("/checkout"), null);
    assert.equal(shopSlugFromPathname("/stores"), null);
  });
});

describe("headerAuthSlot", () => {
  const at = (
    pathname: string,
    isLoggedIn: boolean,
    featureAuthEnabled = true,
    guestCtaEnabled = true,
  ) => headerAuthSlot({ featureAuthEnabled, guestCtaEnabled, isLoggedIn, pathname });

  // Đây là điều #1747 sửa: trước đó chip hiện ở MỌI trang khi đã đăng nhập.
  it("KHÔNG hiện chip trên trang cấp thương hiệu, dù đã đăng nhập", () => {
    for (const path of ["/", "/menus", "/orders", "/orders/42", "/select-branch", "/about"]) {
      assert.equal(at(path, true), "none", path);
    }
  });

  it("hiện chip trên trang có cửa hàng", () => {
    for (const path of [
      "/stores/ginza",
      "/takeaway/ginza",
      "/dine-in/ginza/table/abc123",
      "/account/ginza/orders",
      "/vi/takeaway/ginza",
    ]) {
      assert.equal(at(path, true), "chip", path);
    }
  });

  // Cửa hàng đến từ giỏ chứ không từ URL — chip vẫn quy được về đúng chi nhánh.
  it("hiện chip trong luồng mua dù URL trần", () => {
    assert.equal(at("/checkout", true), "chip");
    assert.equal(at("/order-success", true), "chip");
  });

  it("khách vãng lai: guest-cta đúng ở nơi #1717 cho phép, none ở nơi còn lại", () => {
    assert.equal(at("/takeaway/ginza", false), "guest-cta");
    assert.equal(at("/checkout", false), "guest-cta");
    assert.equal(at("/menus", false), "none");
    assert.equal(at("/", false), "none");
  });

  // Flag #47 tắt cả khu tài khoản — không chip, không nút, ở mọi URL.
  it("FEATURES.auth off thì luôn none", () => {
    assert.equal(at("/takeaway/ginza", true, false), "none");
    assert.equal(at("/takeaway/ginza", false, false), "none");
    assert.equal(at("/checkout", true, false), "none");
  });

  /**
   * `authEntryPoints` off = thôi MỜI, không phải tắt tính năng. Hai bài dưới
   * đây là chỗ phân biệt nó với `auth` off: người đã đăng nhập vẫn phải ra
   * `chip`. Gộp hai flag làm một sẽ giấu luôn tài khoản của người đang dùng
   * nó, và trên mobile thì chip là đường duy nhất còn lại vào khu tài khoản.
   */
  it("authEntryPoints off: khách vãng lai không còn nút mời", () => {
    assert.equal(at("/takeaway/ginza", false, true, false), "none");
    assert.equal(at("/checkout", false, true, false), "none");
  });

  it("authEntryPoints off: người đã đăng nhập VẪN thấy chip", () => {
    assert.equal(at("/takeaway/ginza", true, true, false), "chip");
    assert.equal(at("/checkout", true, true, false), "chip");
  });
});
