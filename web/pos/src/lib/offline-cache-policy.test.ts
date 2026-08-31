import { describe, expect, it } from "vitest";
import { hashKey } from "@tanstack/react-query";
import {
  CACHEABLE_QUERY_ROOTS,
  MONEY_QUERY_ROOTS,
  isCacheableQueryKey,
  isMoneyQueryRoot,
  queryCacheKey,
} from "./offline-cache-policy";
import {
  customerKeys,
  effectivePaymentOptionKeys,
  floatingSectionKeys,
  orderKeys,
  orderPaymentKeys,
  paymentMethodKeys,
  revenueKeys,
  shopKeys,
  shopMenuKeys,
  shopOrderSettingsKeys,
  tableKeys,
  voidReasonKeys,
} from "@/hooks/api/query-keys";
import { tillKeys } from "@/hooks/api/use-till";

const SHOP = "quan-1";

/*
 * #1501 — RANH GIỚI TIỀN CỦA CACHE OFFLINE.
 *
 * Test này khẳng định vào CHÍNH các key factory đang chạy, không phải vào
 * chuỗi tự gõ lại. Đổi tên root ở `query-keys.ts` mà quên cập nhật policy thì
 * đỏ ở đây — chứ không phải lặng lẽ rơi khỏi allowlist (mất cache, còn chịu
 * được) hoặc lặng lẽ rơi vào allowlist (cache tiền, không chịu được).
 */
describe("offline-cache-policy — đường tiền không bao giờ được cache", () => {
  const MONEY_KEYS: Array<[string, readonly unknown[]]> = [
    ["orders.list", orderKeys.list(SHOP, { status: "open" })],
    ["orders.detail", orderKeys.detail(SHOP, "o-1")],
    ["orders.history", orderKeys.history(SHOP)],
    ["order-payments", orderPaymentKeys.list(SHOP, "o-1")],
    ["payment-methods", paymentMethodKeys.list(SHOP)],
    ["effective-payment-options", effectivePaymentOptionKeys.list(SHOP, "vi")],
    ["till.current", tillKeys.current(SHOP)],
    ["till.tenderTypes", tillKeys.tenderTypes(SHOP, "vi")],
    ["till.reconciliation", tillKeys.reconciliation(SHOP, "s-1")],
    ["till.gapPreview", tillKeys.gapPreview(SHOP)],
    ["till.unresolvedOrders", tillKeys.unresolvedOrders(SHOP)],
    ["revenue.summary", revenueKeys.summary(SHOP, {}, "vi")],
    ["customer-outstanding", customerKeys.outstanding(SHOP, "c-1")],
  ];

  it.each(MONEY_KEYS)("từ chối %s", (_label, key) => {
    expect(isCacheableQueryKey(key)).toBe(false);
  });

  it("mọi root trong MONEY_QUERY_ROOTS đều bị allowlist loại", () => {
    for (const root of MONEY_QUERY_ROOTS) {
      expect(CACHEABLE_QUERY_ROOTS).not.toContain(root);
      expect(isMoneyQueryRoot(root)).toBe(true);
      expect(isCacheableQueryKey([root, SHOP])).toBe(false);
    }
  });

  it("`till` là root RIÊNG, không nằm dưới `shop` được cho phép", () => {
    // Nếu ai đó đổi tillKeys thành ["shop", slug, "till"] thì test này đỏ —
    // và đó chính là lúc cho phép "shop" bắt đầu kéo theo ca thu ngân.
    expect(tillKeys.current(SHOP)[0]).toBe("till");
    expect(isCacheableQueryKey(tillKeys.current(SHOP))).toBe(false);
  });
});

describe("offline-cache-policy — dữ liệu tham chiếu được cache", () => {
  const READ_KEYS: Array<[string, readonly unknown[]]> = [
    ["shop.detail", shopKeys.detail(SHOP)],
    ["shop.settings.order", shopOrderSettingsKeys.get(SHOP)],
    ["shop-menus.list", shopMenuKeys.list(SHOP, "vi")],
    ["shop-menus.byDay", shopMenuKeys.byDay(SHOP, 3, "vi")],
    ["tables.list", tableKeys.list(SHOP, {})],
    ["void-reasons.list", voidReasonKeys.list(SHOP, "vi")],
    ["floating-sections.open", floatingSectionKeys.open(SHOP, "vi")],
  ];

  it.each(READ_KEYS)("cho phép %s", (_label, key) => {
    expect(isCacheableQueryKey(key)).toBe(true);
  });
});

describe("offline-cache-policy — mặc định là TỪ CHỐI", () => {
  it("root chưa từng thấy bao giờ thì không được cache", () => {
    expect(isCacheableQueryKey(["payouts", SHOP])).toBe(false);
    expect(isCacheableQueryKey(["some-future-domain"])).toBe(false);
  });

  it("key rỗng hoặc root không phải chuỗi thì không được cache", () => {
    expect(isCacheableQueryKey([])).toBe(false);
    expect(isCacheableQueryKey([{ shop: SHOP }])).toBe(false);
    expect(isCacheableQueryKey([42])).toBe(false);
    expect(isMoneyQueryRoot(42)).toBe(false);
  });
});

describe("queryCacheKey", () => {
  it("dùng đúng hàm băm của TanStack — không phải JSON.stringify tự viết", () => {
    const key = shopMenuKeys.list(SHOP, "vi", { a: 1 });
    expect(queryCacheKey(key)).toBe(hashKey(key));
  });

  it("thứ tự khoá trong object filter không sinh ra hai bản ghi", () => {
    const a = ["shop-menus", SHOP, "list", "vi", { a: 1, b: 2 }];
    const b = ["shop-menus", SHOP, "list", "vi", { b: 2, a: 1 }];
    expect(queryCacheKey(a)).toBe(queryCacheKey(b));
  });
});
