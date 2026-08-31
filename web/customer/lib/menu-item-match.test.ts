import { describe, it } from "node:test";
import assert from "node:assert/strict";

import { buildMenuItemIndex, findMenuContext, findMenuItem } from "./menu-item-match.ts";

// Dữ liệu dựng theo đúng ca thật ở 人形町店 (#1702): 2 menu cùng active, 1 SKU
// nằm ở cả hai, hai deadline lệch 30 phút.
const MENU_A = "019f6efa-menu-a"; // 人形町店 メニュー — end 22:00 → hạn 22:15
const MENU_B = "019f7ee4-menu-b"; // お持ち帰り        — end 21:30 → hạn 21:45
const SKU_PHO = "019f6ed5-sku-pho";

function item(overrides: Record<string, unknown>) {
  return {
    id: "line-x",
    name: "Phở rau",
    price: 1100,
    image: null,
    status: "available",
    ...overrides,
  } as never;
}

function payload() {
  return [
    {
      id: "cat-a",
      name: "人形町店 section",
      items: [
        item({
          id: "line-a",
          sku_id: SKU_PHO,
          menu_id: MENU_A,
          menu_name: "人形町店 メニュー",
          menu_end_time: "22:00:00",
          item_deadline: "2026-08-03T22:15:00+09:00",
        }),
      ],
    },
    {
      id: "cat-b",
      name: "お持ち帰り section",
      items: [
        item({
          id: "line-b",
          sku_id: SKU_PHO,
          menu_id: MENU_B,
          menu_name: "お持ち帰り",
          menu_end_time: "21:30:00",
          item_deadline: "2026-08-03T21:45:00+09:00",
        }),
      ],
    },
  ] as never;
}

describe("findMenuItem — SKU trùng giữa hai menu cùng active (#1702)", () => {
  it("trả đúng DÒNG khách đã bấm khi biết `id`, không phải bản đầu/cuối theo sku", () => {
    const index = buildMenuItemIndex(payload());

    const fromB = findMenuItem(index, { id: "line-b", sku_id: SKU_PHO, menu_id: MENU_B });
    assert.equal(fromB.kind, "exact-line");
    assert.equal(fromB.item?.menu_id, MENU_B);
    assert.equal(fromB.item?.item_deadline, "2026-08-03T21:45:00+09:00");

    const fromA = findMenuItem(index, { id: "line-a", sku_id: SKU_PHO, menu_id: MENU_A });
    assert.equal(fromA.kind, "exact-line");
    assert.equal(fromA.item?.menu_id, MENU_A);
  });

  it("mất `id` (dòng menu được dựng lại) thì `menu_id` giữ món ở đúng menu của nó", () => {
    const index = buildMenuItemIndex(payload());

    const match = findMenuItem(index, { id: "line-b-cu", sku_id: SKU_PHO, menu_id: MENU_B });
    assert.equal(match.kind, "same-menu");
    assert.equal(match.item?.menu_id, MENU_B);
  });

  it("menu của món đã đóng → rơi về sku, lấy bản ĐẦU TIÊN (priority cao nhất)", () => {
    const index = buildMenuItemIndex(payload());

    const match = findMenuItem(index, {
      id: "line-da-bien-mat",
      sku_id: SKU_PHO,
      menu_id: "019f0000-menu-sang-da-dong",
    });
    assert.equal(match.kind, "cross-menu");
    assert.equal(match.item?.menu_id, MENU_A);
  });

  it("không còn ở đâu cả → null", () => {
    const index = buildMenuItemIndex(payload());

    const match = findMenuItem(index, { id: "khong-co", sku_id: "sku-khong-co", menu_id: MENU_A });
    assert.equal(match.kind, null);
    assert.equal(match.item, null);
  });

  it("payload rỗng / thiếu không làm nổ index", () => {
    assert.equal(findMenuItem(buildMenuItemIndex(null), { sku_id: SKU_PHO }).kind, null);
    assert.equal(findMenuItem(buildMenuItemIndex([]), { sku_id: SKU_PHO }).kind, null);
  });
});

describe("findMenuItem — floating section (khung giờ ưu đãi)", () => {
  const withFloating = () =>
    [
      {
        id: "floating-section-1",
        name: "Khung giờ ưu đãi",
        is_floating_section: true,
        items: [
          item({
            id: "spotlight-1",
            sku_id: SKU_PHO,
            menu_id: MENU_A,
            menu_name: "Khung giờ ưu đãi",
            price: 800,
            item_deadline: "2026-08-03T22:15:00+09:00",
          }),
        ],
      },
      ...payload(),
    ] as never;

  it("KHÔNG được coi bản spotlight là bản thay thế của một món menu", () => {
    const index = buildMenuItemIndex(withFloating());

    // Spotlight đứng ĐẦU payload; nếu nó lọt vào index theo sku thì
    // first-write-wins sẽ trả nó ở đây.
    const match = findMenuItem(index, { sku_id: SKU_PHO, menu_id: "menu-da-dong" });
    assert.equal(match.kind, "cross-menu");
    assert.equal(match.item?.price, 1100);
    assert.equal(match.item?.id, "line-a");
  });

  it("nhưng món THÊM TỪ spotlight vẫn tìm lại được chính nó qua `id`", () => {
    const index = buildMenuItemIndex(withFloating());

    const match = findMenuItem(index, { id: "spotlight-1", sku_id: SKU_PHO, menu_id: MENU_A });
    assert.equal(match.kind, "exact-line");
    assert.equal(match.item?.price, 800);
  });
});

describe("findMenuContext", () => {
  const menus = [
    { menu_id: MENU_A, menu_name: "人形町店 メニュー", cart_deadline_iso: "2026-08-03T22:15:00+09:00" },
    { menu_id: MENU_B, menu_name: "お持ち帰り", cart_deadline_iso: "2026-08-03T21:45:00+09:00" },
  ];

  it("trả ngữ cảnh của đúng menu được hỏi", () => {
    assert.equal(findMenuContext(menus, MENU_B)?.cart_deadline_iso, "2026-08-03T21:45:00+09:00");
  });

  it("menu không có trong danh sách / thiếu tham số → null", () => {
    assert.equal(findMenuContext(menus, "menu-la"), null);
    assert.equal(findMenuContext(menus, null), null);
    assert.equal(findMenuContext(undefined, MENU_A), null);
  });
});
