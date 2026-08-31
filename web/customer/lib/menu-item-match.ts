import type { MenuCategory, MenuItem } from "@/data/menu";

/**
 * #1702 — tìm lại một món của giỏ trong payload menu hiện tại.
 *
 * Từ #1185 view menu GỘP nhiều menu đang active, nên trong CÙNG một payload một
 * `sku_id` có thể trỏ tới nhiều item khác menu, khác `item_deadline`, và (nếu dữ
 * liệu lệch) khác giá. Match bằng `sku_id` trần vì thế không còn xác định: trước
 * #1702 `addToCart` dùng `.find()` (lấy bản ĐẦU) còn reducer `RECONCILE` dùng
 * `Map.set()` (lấy bản CUỐI) — hai kết quả ngược nhau trên cùng một payload.
 *
 * Module này là bản luật DUY NHẤT cho cả hai đường.
 */

/** Ngữ cảnh của một menu được gộp vào view (`data.menus[]`, #1702). */
export interface MergedMenuContext {
  menu_id: string;
  menu_name?: string | null;
  schedule_start_time?: string | null;
  schedule_end_time?: string | null;
  cart_timeout_minutes?: number | null;
  cart_deadline_iso?: string | null;
}

/** Định danh phía giỏ của một món, đủ để tìm lại nó trong payload. */
export interface MenuItemRef {
  /** `menuProduct.id` (hoặc `floatingSectionProduct.id`) — duy nhất trong 1 payload. */
  id?: string;
  sku_id?: string;
  /** Menu mà món ĐANG được ghi nhận là thuộc về (cart item's `menuId`). */
  menu_id?: string;
}

/**
 * - `exact-line`  — trúng đúng dòng menu đã bấm (`id`). Menu của món còn mở.
 * - `same-menu`   — cùng `menu_id`, dòng đổi `id`. Menu của món còn mở.
 * - `cross-menu`  — chỉ còn khớp `sku_id`: menu cũ đã đóng / món bị gỡ khỏi menu đó.
 * - `null`        — không còn ở đâu cả.
 */
export type MenuMatchKind = "exact-line" | "same-menu" | "cross-menu" | null;

export interface MenuMatch {
  item: MenuItem | null;
  kind: MenuMatchKind;
}

export interface MenuItemIndex {
  byId: Map<string, MenuItem>;
  byMenuAndSku: Map<string, MenuItem>;
  bySku: Map<string, MenuItem>;
}

const NO_MATCH: MenuMatch = { item: null, kind: null };

function menuSkuKey(menuId: string, skuId: string): string {
  // \u0000 không xuất hiện trong uuid nên không có đường ghép nhầm hai khoá.
  return `${menuId}\u0000${skuId}`;
}

/**
 * Đánh index một payload menu (đã gộp) để tra cứu ba tầng.
 *
 * Tầng `sku_id` **bỏ qua** floating section: spotlight nhân bản món của menu ở
 * giá khuyến mãi, nên coi nó là "bản thay thế" của một món menu là sai. Tầng `id`
 * thì quét cả floating — món khách thêm TỪ spotlight phải tìm lại được chính nó.
 *
 * Cả hai map theo sku đều **first-write-wins**, tức bản của menu có priority cao
 * nhất thắng. Ổn định theo thứ tự payload, không phụ thuộc vị trí trong mảng.
 */
export function buildMenuItemIndex(categories: MenuCategory[] | null | undefined): MenuItemIndex {
  const index: MenuItemIndex = {
    byId: new Map(),
    byMenuAndSku: new Map(),
    bySku: new Map(),
  };

  for (const category of categories ?? []) {
    for (const item of category.items ?? []) {
      if (item.id && !index.byId.has(item.id)) {
        index.byId.set(item.id, item);
      }
      if (category.is_floating_section || !item.sku_id) {
        continue;
      }
      if (item.menu_id) {
        const key = menuSkuKey(item.menu_id, item.sku_id);
        if (!index.byMenuAndSku.has(key)) {
          index.byMenuAndSku.set(key, item);
        }
      }
      if (!index.bySku.has(item.sku_id)) {
        index.bySku.set(item.sku_id, item);
      }
    }
  }

  return index;
}

/** Tra ngữ cảnh của một menu trong `menus[]`. */
export function findMenuContext(
  menus: MergedMenuContext[] | null | undefined,
  menuId: string | undefined | null,
): MergedMenuContext | null {
  if (!menuId || !menus) return null;
  return menus.find((m) => m.menu_id === menuId) ?? null;
}

/** Tìm bản hiện tại của một món trong giỏ. Xem {@link MenuMatchKind} về ba tầng. */
export function findMenuItem(index: MenuItemIndex, ref: MenuItemRef): MenuMatch {
  if (ref.id) {
    const exact = index.byId.get(ref.id);
    if (exact) return { item: exact, kind: "exact-line" };
  }

  if (ref.menu_id && ref.sku_id) {
    const sameMenu = index.byMenuAndSku.get(menuSkuKey(ref.menu_id, ref.sku_id));
    if (sameMenu) return { item: sameMenu, kind: "same-menu" };
  }

  if (ref.sku_id) {
    const anyMenu = index.bySku.get(ref.sku_id);
    if (anyMenu) return { item: anyMenu, kind: "cross-menu" };
  }

  return NO_MATCH;
}
