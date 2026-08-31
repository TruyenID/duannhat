"use client";

import {
  createContext,
  useContext,
  useReducer,
  useState,
  useEffect,
  useCallback,
  useMemo,
  useRef,
  type ReactNode,
} from "react";
import type { MenuItem, MenuCategory } from "@/data/menu";
import {
  buildMenuItemIndex,
  findMenuContext,
  findMenuItem,
  type MergedMenuContext,
} from "@/lib/menu-item-match";
import { computePriceSync } from "@/lib/cart-price-sync";
import { useBrand } from "@/context/brand-context";
import { useAuth } from "@/context/auth-context";
import { apiFetch } from "@/lib/api";
import { shouldClearCartOnBranchChange } from "@/lib/cart-branch-guard";
import { resolveSeededBranchSlug } from "@/lib/cart-branch-seed";
import { resolveHydrationOrderMode, routeOrderMode } from "@/lib/order-mode-route";

// --- Types ---

/**
 * Topping selections per group, per item: { groupId: { itemId: qty } }
 * Example: { "size-bowl": { "lon": 1 }, "topping-bun-pho": { "them-thit": 2, "trung-long": 1 } }
 */
export type ToppingQuantities = Record<string, Record<string, number>>;

/**
 * Selected variant SKU for each topping item (when topping has variants)
 * Example: { "topping-item-id-1": "variant-sku-id-abc" }
 */
export type ToppingItemVariantSelections = Record<string, string>;

export interface CartItem {
  id: string;
  product: MenuItem;
  selections: Record<string, string[]>; // Product options/variants
  quantity: number;
  unitPrice: number;
  toppingQuantities?: ToppingQuantities; // Plan 015 topping selections
  toppingItemVariants?: ToppingItemVariantSelections; // Plan 015 topping variant SKUs
  note?: string; // Ghi chú cho khách (per-item special request)
  // Per-item cart timeout (Option A) — each item locks in its own deadline
  // when added, based on the active menu + shop timeout setting at that moment.
  menuId?: string;         // Menu ID món này thuộc về (khi add)
  menuName?: string;       // Menu name (vd "Menu Sáng", "Menu Trưa")
  menuEndTime?: string;    // Giờ kết thúc menu (HH:mm:ss)
  addedAt?: string;        // ISO timestamp khi add món vào cart
  itemDeadline?: string;   // ISO timestamp deadline — menuEndTime + timeout_minutes
  // Cross-time reconciliation (TC-DICONF03). Khi menu chuyển ca (sáng→trưa),
  // `reconcileCrossTimeItems` so giá món với menu mới: nếu món biến mất khỏi
  // menu mới HOẶC giá khác lúc add → set true để chặn đặt hàng (coi như hết
  // hạn) ngay cả khi còn trong grace window. Món còn giá y nguyên thì được
  // re-ref sang menu mới và cờ này về false.
  priceChanged?: boolean;
  /**
   * #1715 — giá dòng này vừa được đồng bộ lại với menu đang phát (khung giờ ưu
   * đãi đóng, Happy Hour hết, hoặc admin sửa giá). `from` giữ giá lúc khách THÊM
   * món, không phải giá của lần chỉnh trước, nên badge luôn nói đúng "lúc bạn
   * chọn là bao nhiêu".
   *
   * KHÔNG dùng chung `priceChanged`: cờ đó nghĩa là *chặn đặt*
   * (`isItemExpired` trả true), tức đúng cái hành vi mà hướng "cập nhật + báo"
   * loại bỏ.
   */
  priceAdjusted?: { from: number; to: number };
}

interface CartState {
  items: CartItem[];
  justAdded: string | null; // cart item id, for animation trigger
}

type CartAction =
  | { type: "ADD_ITEM"; payload: CartItem }
  | { type: "REMOVE_ITEM"; payload: string }
  | { type: "UPDATE_QTY"; payload: { id: string; qty: number } }
  | { type: "CLEAR" }
  | { type: "CLEAR_JUST_ADDED" }
  | { type: "ACK_PRICE_ADJUSTED"; payload: string | null }
  | { type: "APPLY_SERVER_PRICES"; payload: Array<{ id: string; unitPrice: number }> }
  | { type: "HYDRATE"; payload: CartItem[] }
  | {
      type: "RECONCILE";
      payload: ReconcilePayload;
    };

/**
 * #1702 — reconcile nhận nguyên `categories` (giữ được cờ `is_floating_section`)
 * và `menus[]` (mọi menu đang được gộp), thay cho một mảng product phẳng + ngữ
 * cảnh của riêng menu head. Bốn field top-level chỉ còn là fallback cho BE cũ.
 */
export interface ReconcilePayload {
  menuId: string;
  menuName: string;
  scheduleEndTime: string | null;
  cartTimeoutMinutes: number | null;
  categories: MenuCategory[];
  menus?: MergedMenuContext[] | null;
}

// --- Helpers ---

export function generateCartItemId(
  productId: string,
  selections: Record<string, string[]>,
  toppingQuantities?: ToppingQuantities,
  toppingItemVariants?: ToppingItemVariantSelections,
): string {
  const sortedSel = Object.keys(selections)
    .sort()
    .reduce<Record<string, string[]>>((acc, key) => {
      acc[key] = [...selections[key]].sort();
      return acc;
    }, {});

  // Two cart items with the same product but different topping picks must NOT
  // merge — otherwise the second pick's toppingQuantities/toppingItemVariants
  // get silently dropped by the ADD_ITEM reducer, leaving the order with no
  // selection for a mandatory group → toppings_below_min on the server.
  const sortedToppings: Record<string, Record<string, number>> = {};
  if (toppingQuantities) {
    for (const groupId of Object.keys(toppingQuantities).sort()) {
      const inner = toppingQuantities[groupId];
      const innerSorted: Record<string, number> = {};
      for (const itemId of Object.keys(inner).sort()) {
        if (inner[itemId] > 0) innerSorted[itemId] = inner[itemId];
      }
      if (Object.keys(innerSorted).length > 0) sortedToppings[groupId] = innerSorted;
    }
  }

  const sortedVariants: Record<string, string> = {};
  if (toppingItemVariants) {
    for (const itemId of Object.keys(toppingItemVariants).sort()) {
      sortedVariants[itemId] = toppingItemVariants[itemId];
    }
  }

  return (
    productId +
    "_" +
    JSON.stringify(sortedSel) +
    "_" +
    JSON.stringify(sortedToppings) +
    "_" +
    JSON.stringify(sortedVariants)
  );
}

/**
 * Resolve the best image URL for a cart item: prefer a selected
 * variant's own photo (walking options in reverse so the most
 * specific / deepest pick wins), falling back to the product's
 * gallery image. Returns null if nothing is available so callers
 * can render a placeholder.
 */
export function resolveCartItemImage(item: CartItem): string | null {
  const { product, selections } = item;
  if (product.options) {
    for (let i = product.options.length - 1; i >= 0; i--) {
      const option = product.options[i];
      const selectedIds = selections[option.id];
      if (!selectedIds || selectedIds.length === 0) continue;
      const match = option.variants.find(
        (v) => selectedIds.includes(v.id) && v.image,
      );
      if (match?.image) return match.image;
    }
  }
  return product.image ?? null;
}

export function calculateUnitPrice(
  product: MenuItem,
  selections: Record<string, string[]>,
): number {
  let extra = 0;
  if (product.options) {
    for (const opt of product.options) {
      const selected = selections[opt.id];
      if (!selected) continue;
      for (const variant of opt.variants) {
        if (selected.includes(variant.id)) {
          extra += variant.price;
        }
      }
    }
  }
  return product.price + extra;
}

// --- Reducer ---

function cartReducer(state: CartState, action: CartAction): CartState {
  switch (action.type) {
    case "ADD_ITEM": {
      const existing = state.items.find((i) => i.id === action.payload.id);
      if (existing) {
        return {
          ...state,
          justAdded: action.payload.id,
          items: state.items.map((i) =>
            i.id === action.payload.id
              ? { ...i, quantity: i.quantity + action.payload.quantity }
              : i,
          ),
        };
      }
      return {
        ...state,
        justAdded: action.payload.id,
        items: [...state.items, action.payload],
      };
    }
    case "REMOVE_ITEM":
      return {
        ...state,
        items: state.items.filter((i) => i.id !== action.payload),
      };
    case "UPDATE_QTY": {
      if (action.payload.qty <= 0) {
        return {
          ...state,
          items: state.items.filter((i) => i.id !== action.payload.id),
        };
      }
      return {
        ...state,
        items: state.items.map((i) =>
          i.id === action.payload.id
            ? { ...i, quantity: action.payload.qty }
            : i,
        ),
      };
    }
    case "CLEAR":
      return { items: [], justAdded: null };
    case "ACK_PRICE_ADJUSTED": {
      // Khách đã đọc thông báo "giá vừa đổi" → gỡ badge. `null` = gỡ hết.
      const target = action.payload;
      if (!state.items.some((i) => i.priceAdjusted && (target === null || i.id === target))) {
        return state;
      }
      return {
        ...state,
        items: state.items.map((i) =>
          i.priceAdjusted && (target === null || i.id === target)
            ? { ...i, priceAdjusted: undefined }
            : i,
        ),
      };
    }
    case "APPLY_SERVER_PRICES": {
      // #1715 — backend vừa từ chối đơn (409) kèm giá thật của từng dòng lệch.
      // Đây là con số CHÍNH XÁC sẽ được tính, chính xác hơn cả lượt reconcile kế
      // tiếp (reconcile đọc menu, còn cái này đọc thẳng kết quả định giá).
      const byId = new Map(action.payload.map((u) => [u.id, u.unitPrice]));
      let touched = false;
      const items = state.items.map((item) => {
        const next = byId.get(item.id);
        if (next === undefined || next === item.unitPrice) return item;
        touched = true;
        return {
          ...item,
          unitPrice: next,
          priceAdjusted: { from: item.priceAdjusted?.from ?? item.unitPrice, to: next },
        };
      });
      return touched ? { ...state, items } : state;
    }
    case "CLEAR_JUST_ADDED":
      return { ...state, justAdded: null };
    case "HYDRATE":
      return { ...state, items: action.payload };
    case "RECONCILE": {
      // Cross-time reconciliation (TC-DICONF03). Đối chiếu từng món trong giỏ
      // với view menu hiện tại:
      //   • món vẫn thuộc một menu ĐANG MỞ → giữ nguyên hạn của chính nó, chỉ
      //     đồng bộ chuỗi dịch được (#367).
      //   • menu của món đã đóng, nhưng món còn ở menu khác + giá y nguyên →
      //     re-ref sang menu đó, nhận hạn CỦA MENU ĐÓ → vẫn đặt được.
      //   • biến mất / đổi giá → set priceChanged = true → chặn đặt hàng.
      //
      // #1702 — trước đây khớp bằng `sku_id` trần và so `item.menuId` với field
      // top-level. Từ #1185 view GỘP nhiều menu, nên (a) một sku_id trỏ tới
      // nhiều item khác menu khác hạn, và (b) top-level chỉ là ngữ cảnh của menu
      // ĐẦU TIÊN — mọi món của menu thứ 2 trở đi bị coi là "menu cũ" rồi bị gán
      // lại hạn của menu head dù menu của nó vẫn đang mở. Giờ dùng chung
      // `findMenuItem` với addToCart; xem lib/menu-item-match.ts.
      const { menuId, menuName, scheduleEndTime, cartTimeoutMinutes, categories, menus } =
        action.payload;

      const index = buildMenuItemIndex(categories);

      // Đường chót khi BE cũ không trả `item_deadline` per-item: tính từ giờ
      // đóng menu + timeout. Dùng đồng hồ TRÌNH DUYỆT nên có thể lệch múi giờ
      // của quán (#1091) — chỉ chấp nhận được vì mọi BE hiện tại đều trả
      // `item_deadline`/`menus[]` do backend tính theo `branches.timezone`.
      let legacyDeadlineIso: string | undefined;
      if (typeof cartTimeoutMinutes === "number" && scheduleEndTime) {
        const todayStr = new Date().toISOString().slice(0, 10);
        const menuEndMs = new Date(`${todayStr}T${scheduleEndTime}`).getTime();
        legacyDeadlineIso = new Date(
          menuEndMs + cartTimeoutMinutes * 60 * 1000,
        ).toISOString();
      }

      let changed = false;
      const items = state.items.map((item) => {
        const { item: fresh, kind } = findMenuItem(index, {
          id: item.product.id,
          sku_id: item.product.sku_id,
          menu_id: item.menuId,
        });

        // Menu của món vẫn đang mở (trúng đúng dòng, hoặc cùng menu_id).
        // Issue #367 — keep translatable strings (name / description /
        // toppingGroup labels) in sync with the active locale. Snapshot fields
        // like sku_id / price / options are stable across locales, so we patch
        // the locale-sensitive subset and leave the rest alone. Hạn của món KHÔNG
        // bị đụng tới: nó là hạn của menu mà khách đã bấm.
        if (fresh && (kind === "exact-line" || kind === "same-menu")) {
          const nameDiffers = fresh.name !== item.product.name;
          const descDiffers = (fresh.description ?? null) !== (item.product.description ?? null);
          const toppingGroupsDiffer = JSON.stringify(fresh.toppingGroups ?? null)
            !== JSON.stringify(item.product.toppingGroups ?? null);
          // Món thêm trước khi có ngữ cảnh menu (đặt lại từ lịch sử đơn, hoặc
          // enrichment lỗi mạng) — điền vào chỗ trống, không ghi đè hạn đã có.
          const adoptsMenu = !item.menuId && Boolean(fresh.menu_id);

          // #1715 — giỏ chụp giá lúc bấm card, backend thì LUÔN định giá lại lúc
          // tạo đơn. Khung giờ ưu đãi đóng lúc 20:00 mà món vào giỏ từ 19:59:
          // giỏ hiện ¥800, đơn ra ¥1,100 và không màn nào hỏi server trước khi
          // commit. Nhánh này (menu của món VẪN đang mở) trước đây cố ý không so
          // giá vì sợ chặn hàng loạt giỏ khi admin sửa giá giữa ca — nhưng phản
          // ứng bây giờ là CẬP NHẬT + BÁO, không phải chặn, nên nỗi lo đó không
          // còn. Xem lib/cart-price-sync.ts.
          const sync = computePriceSync({
            product: item.product,
            fresh,
            selections: item.selections,
            unitPrice: item.unitPrice,
          });
          // Không so được (variant đã chọn biến mất) → chặn đặt thay vì đoán giá.
          const blocks = sync.status === "unresolvable";
          const adjustsPrice = sync.status === "adjusted";
          const clearsPriceChanged = item.priceChanged === true && !blocks;
          const marksPriceChanged = item.priceChanged !== true && blocks;

          if (
            !nameDiffers && !descDiffers && !toppingGroupsDiffer
            && !adoptsMenu && !clearsPriceChanged && !marksPriceChanged && !adjustsPrice
          ) {
            // Cùng reference ⇒ không ghi lại storage, không dựng lại ticker, không
            // re-render cả cây. Reconcile chạy mỗi 30-60s nên đường này phải im.
            return item;
          }
          changed = true;
          return {
            ...item,
            ...(adoptsMenu
              ? {
                  menuId: fresh.menu_id,
                  menuName: fresh.menu_name ?? item.menuName,
                  menuEndTime: fresh.menu_end_time ?? item.menuEndTime,
                  itemDeadline: item.itemDeadline ?? fresh.item_deadline ?? undefined,
                }
              : {}),
            ...(clearsPriceChanged ? { priceChanged: false } : {}),
            ...(marksPriceChanged ? { priceChanged: true } : {}),
            ...(adjustsPrice
              ? {
                  unitPrice: sync.unitPrice,
                  priceAdjusted: {
                    from: item.priceAdjusted?.from ?? item.unitPrice,
                    to: sync.unitPrice,
                  },
                }
              : {}),
            product: adjustsPrice
              ? // Thay HẲN bản chụp khi giá đổi. Nếu chỉ sửa `unitPrice` mà giữ
                // product cũ thì lượt reconcile sau lại tính ra ĐÚNG cùng một
                // delta và cộng tiếp — poll 30s ở màn xác nhận biến ¥800 thành
                // ¥3,800 trong 5 phút. Thay snapshot cũng kéo theo `tax_rate` và
                // `active_promotion` mới, nên preview thuế và cái gạch ngang trên
                // thẻ giỏ không còn nói theo khuyến mãi đã hết.
                { ...fresh, categoryName: item.product.categoryName ?? fresh.categoryName }
              : {
                  ...item.product,
                  name: fresh.name,
                  description: fresh.description ?? item.product.description,
                  // Preserve categoryName the consumer set explicitly; only
                  // overwrite if the fresh menu shipped one.
                  categoryName: fresh.categoryName ?? item.product.categoryName,
                  // Topping group + topping item labels are translatable too.
                  toppingGroups: fresh.toppingGroups ?? item.product.toppingGroups,
                },
          };
        }

        // Menu của món không còn — món chỉ còn khớp theo sku ở một menu khác.
        const samePrice =
          fresh !== null &&
          calculateUnitPrice(fresh, item.selections) ===
            calculateUnitPrice(item.product, item.selections);

        if (fresh && samePrice) {
          changed = true;
          // Stamp theo menu của BẢN THAY THẾ, không phải menu head của view.
          const freshMenuContext = findMenuContext(menus, fresh.menu_id);
          return {
            ...item,
            product: { ...fresh, categoryName: item.product.categoryName },
            menuId: fresh.menu_id ?? menuId,
            menuName: fresh.menu_name ?? menuName,
            menuEndTime:
              fresh.menu_end_time ?? freshMenuContext?.schedule_end_time ?? scheduleEndTime ?? item.menuEndTime,
            itemDeadline:
              fresh.item_deadline ?? freshMenuContext?.cart_deadline_iso ?? legacyDeadlineIso ?? item.itemDeadline,
            priceChanged: false,
          };
        }

        if (!item.priceChanged) {
          changed = true;
          return { ...item, priceChanged: true };
        }
        return item;
      });

      // Trả về cùng reference khi không có gì đổi → tránh re-render thừa / loop.
      return changed ? { ...state, items } : state;
    }
    default:
      return state;
  }
}

// --- Context ---

export type OrderType = "dine_in" | "takeaway";
export type PickupType = "immediate" | "scheduled";

export interface DineInTable {
  id: string;
  number: string;
  seats: number;
  zoneName: string;
  qr_token?: string;
}

export interface PickupTimeData {
  pickup_type: PickupType;
  scheduled_pickup_time: string | null;
}

export interface CartMetadata {
  created_at: string; // ISO timestamp when first item added
  branch_slug: string;
  timeout_minutes: number | null;
  deadline_iso: string | null; // Server-computed deadline
}

interface CartContextValue {
  items: CartItem[];
  totalItems: number;
  totalPrice: number;
  justAdded: string | null;
  orderType: OrderType;
  setOrderType: (type: OrderType) => void;
  dineInTable: DineInTable | null;
  setDineInTable: (table: DineInTable | null) => void;
  isTableLocked: boolean;
  setIsTableLocked: (locked: boolean) => void;
  pickupTimeData: PickupTimeData;
  setPickupTimeData: (data: PickupTimeData) => void;
  addToCart: (item: CartItem) => Promise<void>; // Async để fetch cart-config
  removeFromCart: (id: string) => void;
  updateQuantity: (id: string, quantity: number) => void;
  clearCart: () => void;
  clearCartItems: () => void; // Clear items + metadata only, preserve table/mode context
  clearJustAdded: () => void;
  // #1715 — giá dòng vừa được đồng bộ lại với menu đang phát.
  acknowledgePriceAdjusted: (id?: string | null) => void;
  applyServerPrices: (updates: Array<{ id: string; unitPrice: number }>) => void;
  // Per-item timeout helpers (Option A) — each item has its own deadline
  getItemSecondsRemaining: (item: CartItem) => number;
  isItemExpired: (item: CartItem) => boolean;
  // Cross-time reconciliation (TC-DICONF03) — re-check carried-over items
  // against the currently-active menu (same price → re-ref, else block).
  reconcileCrossTimeItems: (menu: ReconcilePayload) => void;
  // Global menu timeout (for menu-expired modal + UI)
  cartMetadata: CartMetadata | null;
  setCartMetadata: (metadata: CartMetadata | null) => void;
  timeoutDeadline: Date | null;
  isExpired: boolean;
  secondsRemaining: number;
  // Coupon code persistence (cart drawer → checkout)
  appliedCouponCode: string | null;
  setAppliedCouponCode: (code: string | null) => void;
}

const CartContext = createContext<CartContextValue | null>(null);

// Dynamic localStorage keys — takeaway & dine-in carts are isolated.
// Dine-in cart is scoped per table (qr_token) so each table has its own cart.
function getCartStorageKey(orderType: OrderType, tableQrToken?: string): string {
  if (orderType === "takeaway") {
    return "betoya-cart-takeaway";
  }
  // Dine-in: cart riêng theo bàn
  if (tableQrToken) {
    return `betoya-cart-dinein-${tableQrToken}`;
  }
  // Fallback nếu chưa set table (không nên xảy ra trong normal flow)
  return "betoya-cart-dinein-pending";
}

function getTimestampStorageKey(orderType: OrderType, tableQrToken?: string): string {
  return `${getCartStorageKey(orderType, tableQrToken)}-timestamp`;
}

const DINE_IN_TABLE_KEY = "betoya-dine-in-table";
const PICKUP_TIME_KEY = "betoya-pickup-time";
const CART_METADATA_KEY = "betoya-cart-metadata";
// Durable branch slug for the persisted takeaway cart. Written whenever a
// non-empty takeaway cart is persisted so the cross-branch guard can be seeded
// on a cold load — independent of CART_METADATA_KEY, which only exists when the
// active menu carries a cart deadline. Without this, a reload of a takeaway
// cart whose menu has no timeout left the guard un-seeded and a subsequent
// deep-link into a different branch smuggled a cross-branch cart into checkout.
const TAKEAWAY_CART_BRANCH_KEY = "betoya-cart-takeaway-branch";
const ORDER_TYPE_KEY = "betoya-order-type";
const APPLIED_COUPON_KEY = "betoya-applied-coupon";

// Fallback thumbnail used when a CartItem's product.image is missing.
// This mirrors the hero fallback in ProductModal so list, detail, and cart
// all stay visually consistent even if the backend gallery hasn't attached
// a dedicated image yet.
const CART_FALLBACK_IMAGE = "/images/craft-pho-bowl.webp";

const GUEST_CART_TTL_MS = 60 * 60 * 1000; // 1 hour for guest users

// Validate + normalize a persisted cart payload. Shared by both hydration
// paths (takeaway from localStorage, dine-in from sessionStorage) so the
// structural checks and the legacy-image backfill stay in exactly one place.
function normalizeStoredCartItems(parsed: unknown): CartItem[] {
  if (!Array.isArray(parsed)) return [];
  const valid = (parsed as CartItem[]).filter((item) => {
    return (
      item &&
      typeof item.id === "string" &&
      item.product &&
      typeof item.product.name === "string" &&
      typeof item.quantity === "number" &&
      item.quantity > 0 &&
      typeof item.unitPrice === "number"
    );
  });
  // Normalize legacy items: ensure product.image is always set so the cart
  // drawer can reliably render a thumbnail even when older payloads or seeds
  // didn't attach a gallery image.
  return valid.map((item) => ({
    ...item,
    // #1715 — thông báo "giá vừa đổi" là chuyện của PHIÊN đang mở, không phải một
    // thuộc tính của món. Giỏ takeaway của khách đã đăng nhập không có TTL, nên để
    // cờ này sống qua reload thì hôm sau mở lại vẫn thấy badge của hôm qua. Lượt
    // reconcile đầu tiên sau khi hydrate sẽ dựng lại nếu giá THẬT SỰ còn lệch.
    priceAdjusted: undefined,
    product: {
      ...item.product,
      image: item.product?.image ?? CART_FALLBACK_IMAGE,
    },
  }));
}

const DEFAULT_PICKUP_TIME: PickupTimeData = {
  pickup_type: "immediate",
  scheduled_pickup_time: null,
};

export function CartProvider({ children }: { children: ReactNode }) {
  const { currentBranch } = useBrand();
  const { isLoggedIn, isLoading: authLoading } = useAuth();
  const [state, dispatch] = useReducer(cartReducer, { items: [], justAdded: null });
  const [orderType, setOrderType] = useState<OrderType>("takeaway");
  const [dineInTable, setDineInTableState] = useState<DineInTable | null>(null);
  const [isTableLocked, setIsTableLocked] = useState(false);
  const [pickupTimeData, setPickupTimeDataState] = useState<PickupTimeData>(DEFAULT_PICKUP_TIME);
  const [cartMetadata, setCartMetadataState] = useState<CartMetadata | null>(null);
  const [appliedCouponCode, setAppliedCouponCodeState] = useState<string | null>(null);
  const [isHydrated, setIsHydrated] = useState(false); // Flag to prevent save before hydrate
  // Ticker for per-item countdown — updates every second to force recompute
  const [nowMs, setNowMs] = useState(() => Date.now());

  // Track previous orderType to detect mode switches
  const previousOrderTypeRef = useRef<OrderType | null>(null);
  // Track the branch slug the current takeaway items belong to, so a change of
  // active branch (via ANY navigation path) can drop a stale cross-branch cart.
  const previousBranchSlugRef = useRef<string | null>(null);

  // Hydrate from localStorage + sessionStorage. Defer qua microtask để tránh
  // setState đồng bộ trong effect body (React 19 set-state-in-effect rule).
  useEffect(() => {
    // Wait for auth to finish loading before hydrating cart
    if (authLoading) {
      return;
    }

    let cancelled = false;
    Promise.resolve().then(() => {
      if (cancelled) return;
      // 1. Restore dine-in table from sessionStorage
      let restoredTable: DineInTable | null = null;
      try {
        const rawTable = sessionStorage.getItem(DINE_IN_TABLE_KEY);
        if (rawTable) {
          const parsed = JSON.parse(rawTable) as { table: DineInTable; locked: boolean };
          restoredTable = parsed.table;
          setDineInTableState(parsed.table);
          setIsTableLocked(parsed.locked);
        }
      } catch { /* ignore */ }

      // 2. Restore pickup time data
      try {
        const rawPickupTime = localStorage.getItem(PICKUP_TIME_KEY);
        if (rawPickupTime) {
          const parsed = JSON.parse(rawPickupTime) as PickupTimeData;
          // Drop stale scheduled times — when the user comes back hours or
          // days later the previously-picked moment is in the past and the
          // backend rejects with `after:now` on order create. Reset to
          // immediate; user can re-pick a fresh slot if they need to.
          if (
            parsed.pickup_type === "scheduled" &&
            parsed.scheduled_pickup_time &&
            new Date(parsed.scheduled_pickup_time).getTime() <= Date.now()
          ) {
            setPickupTimeDataState(DEFAULT_PICKUP_TIME);
            localStorage.removeItem(PICKUP_TIME_KEY);
          } else {
            setPickupTimeDataState(parsed);
          }
        }
      } catch { /* ignore */ }

      // 3. Restore cart — key depends on order type + table
      try {
        const savedOrderType = localStorage.getItem(ORDER_TYPE_KEY) as OrderType | null;

        // A persisted 'dine_in' mode stays meaningful only while its table is
        // still in sessionStorage. Once the tab closes the sitting is over, so
        // resolve back to takeaway.
        //
        // This resolution is load-bearing, not cosmetic. `orderType` state
        // starts at 'takeaway' and the save effect keys off it, so any path
        // that took the dine-in branch WITHOUT calling setOrderType (an empty
        // dine-in session, or no table at all) left orderType at 'takeaway'
        // while ORDER_TYPE_KEY still said 'dine_in' — and the save effect then
        // overwrote the untouched, still-valid takeaway cart with "[]" on the
        // very next render. Same hazard when ORDER_TYPE_KEY is missing.
        // godx-tempo#1697 — the ROUTE outranks the persisted mode.
        //
        // `restoredTable` comes from sessionStorage, so a tab that once scanned
        // a table QR keeps that table until the tab CLOSES — a reload, a
        // bookmark, or a walk back to /takeaway/[shop] all still find it. The
        // old expression read that as "dine-in session" no matter where the
        // customer stood, so hydration flipped orderType to 'dine_in' ON THE
        // TAKEAWAY ROUTE and won the race against that page's own
        // setOrderType("takeaway"). Every item then landed in the TABLE's
        // sessionStorage cart while the takeaway cart stayed empty, and
        // /checkout dead-ended on "Chưa xác định bàn".
        //
        // Routes that serve BOTH modes (/checkout, /orders/[id],
        // /order-confirm/[id]) return null and keep the persisted-mode
        // fallback below — that is the only thing that can tell them apart.
        const effectiveOrderType: OrderType = resolveHydrationOrderMode({
          routeMode: routeOrderMode(window.location.pathname),
          savedOrderType,
          hasRestoredTable: !!restoredTable,
        });

        // Determine storage key based on the resolved mode + table
        const storageKey = getCartStorageKey(effectiveOrderType, restoredTable?.qr_token);
        const timestampKey = getTimestampStorageKey(effectiveOrderType, restoredTable?.qr_token);

        // Restore the cart from the mode's own storage backend:
        //   • takeaway → localStorage (durable across tab close, has guest TTL)
        //   • dine-in  → sessionStorage (per-tab: survives a locale switch or
        //     reload within the same tab, but clears on tab close so each table
        //     sitting starts fresh)
        if (effectiveOrderType === 'dine_in') {
          setOrderType('dine_in'); // Keep state in sync with the persisted mode

          // Drop any legacy dine-in cart older builds may have left in
          // localStorage — dine-in now lives in sessionStorage only.
          if (localStorage.getItem(storageKey)) {
            localStorage.removeItem(storageKey);
            localStorage.removeItem(timestampKey);
          }

          const rawDineIn = sessionStorage.getItem(storageKey);
          if (rawDineIn) {
            const normalized = normalizeStoredCartItems(JSON.parse(rawDineIn));
            if (normalized.length > 0) {
              dispatch({ type: "HYDRATE", payload: normalized });
            }
          }
        } else {
          const raw = localStorage.getItem(storageKey);
          const timestamp = localStorage.getItem(timestampKey);

          if (raw) {
            // Check TTL for guest users (logged-in users have no TTL)
            if (!isLoggedIn && timestamp) {
              const savedAt = parseInt(timestamp, 10);
              const now = Date.now();
              const age = now - savedAt;

              if (age > GUEST_CART_TTL_MS) {
                localStorage.removeItem(storageKey);
                localStorage.removeItem(timestampKey);
                return;
              }
            }

            const normalized = normalizeStoredCartItems(JSON.parse(raw));
            if (normalized.length > 0) {
              setOrderType('takeaway'); // Restore order type
              dispatch({ type: "HYDRATE", payload: normalized });
            }
          }
        }
      } catch (err) {
        console.error('❌ [Cart] Failed to hydrate:', err);
      } finally {
        // Mark as hydrated (even if empty) to allow save effect to run
        setIsHydrated(true);
      }

      let metadataBranchSlug: string | null = null;
      try {
        const rawMeta = localStorage.getItem(CART_METADATA_KEY);
        if (rawMeta) {
          const parsed = JSON.parse(rawMeta) as CartMetadata;
          if (
            parsed &&
            typeof parsed.created_at === "string" &&
            typeof parsed.branch_slug === "string"
          ) {
            setCartMetadataState(parsed);
            metadataBranchSlug = parsed.branch_slug || null;
          }
        }
      } catch { /* ignore invalid metadata */ }

      // Seed the cross-branch guard with the branch the persisted takeaway cart
      // belongs to, so deep-linking straight into a DIFFERENT branch on a cold
      // load still trips the invariant (the guard effect never observed the old
      // branch otherwise). The durable takeaway-branch key is written alongside
      // every persisted takeaway cart, so it survives even when the menu has no
      // cart deadline (CART_METADATA_KEY absent).
      try {
        const persistedBranch = localStorage.getItem(TAKEAWAY_CART_BRANCH_KEY);
        const seed = resolveSeededBranchSlug({
          persistedTakeawayBranchSlug: persistedBranch || null,
          cartMetadataBranchSlug: metadataBranchSlug,
        });
        if (seed) {
          previousBranchSlugRef.current = seed;
        }
      } catch { /* ignore */ }

      // 4. Restore applied coupon code
      try {
        const rawCoupon = sessionStorage.getItem(APPLIED_COUPON_KEY);
        if (rawCoupon) {
          setAppliedCouponCodeState(rawCoupon);
        }
      } catch { /* ignore */ }
    });
    return () => {
      cancelled = true;
    };
  }, [isLoggedIn, authLoading]); // Re-run when auth ready or login state changes

  // Persist cart metadata
  useEffect(() => {
    if (cartMetadata) {
      localStorage.setItem(CART_METADATA_KEY, JSON.stringify(cartMetadata));
    } else {
      localStorage.removeItem(CART_METADATA_KEY);
    }
  }, [cartMetadata]);

  // Persist applied coupon code
  useEffect(() => {
    if (appliedCouponCode) {
      sessionStorage.setItem(APPLIED_COUPON_KEY, appliedCouponCode);
    } else {
      sessionStorage.removeItem(APPLIED_COUPON_KEY);
    }
  }, [appliedCouponCode]);

  // Countdown ticker — runs when any item has a deadline OR menu deadline exists
  useEffect(() => {
    const hasAnyDeadline = state.items.some((item) => item.itemDeadline);
    const hasMenuDeadline = !!cartMetadata?.deadline_iso;
    if (!hasAnyDeadline && !hasMenuDeadline) {
      return;
    }
    const tick = setInterval(() => setNowMs(Date.now()), 1000);
    return () => clearInterval(tick);
  }, [state.items, cartMetadata?.deadline_iso]);

  // REMOVED: Auto-remove expired items
  // Requirement change: expired items should stay in cart (read-only, grouped separately)
  // so user can see what they lost + manually remove if desired.

  // Persist to localStorage on change (ONLY for takeaway, NOT dine-in)
  useEffect(() => {
    // Don't save until hydration is complete
    if (!isHydrated) return;

    // Don't save while auth is loading (might save with wrong login state)
    if (authLoading) return;

    // Determine storage key based on current mode + table
    const storageKey = getCartStorageKey(orderType, dineInTable?.qr_token);
    const timestampKey = getTimestampStorageKey(orderType, dineInTable?.qr_token);

    // ONLY persist cart for TAKEAWAY mode (dine-in cart is in-memory only)
    if (orderType === 'takeaway') {
      localStorage.setItem(storageKey, JSON.stringify(state.items));
      localStorage.setItem(ORDER_TYPE_KEY, orderType);

      // Save timestamp when cart has items (for TTL check on guest users)
      if (state.items.length > 0) {
        localStorage.setItem(timestampKey, Date.now().toString());
      } else {
        localStorage.removeItem(timestampKey);
      }
    } else if (orderType === 'dine_in') {
      // Dine-in: persist to sessionStorage (per-tab) so the cart survives a
      // locale switch or reload within the same tab, yet clears on tab close so
      // each table sitting starts fresh. Never keep it in localStorage.
      localStorage.removeItem(storageKey);
      localStorage.removeItem(timestampKey);
      localStorage.setItem(ORDER_TYPE_KEY, orderType); // Still save order type

      if (state.items.length > 0) {
        sessionStorage.setItem(storageKey, JSON.stringify(state.items));
      } else {
        sessionStorage.removeItem(storageKey);
      }
    }
  }, [state.items, orderType, dineInTable?.qr_token, isLoggedIn, authLoading, isHydrated]);

  // Auto-clear justAdded after animation
  useEffect(() => {
    if (state.justAdded) {
      const timer = setTimeout(() => {
        dispatch({ type: "CLEAR_JUST_ADDED" });
      }, 1500);
      return () => clearTimeout(timer);
    }
  }, [state.justAdded]);

  // Cross-branch cart invariant (plan-002 audit). A takeaway cart belongs to
  // exactly one branch; if the active branch changes to a DIFFERENT branch
  // while a takeaway cart is non-empty, drop the stale items so we never carry
  // a cross-branch / cross-currency cart into checkout. This is enforced
  // centrally here — rather than in a single switcher component — so every
  // switch path is covered: BrandSwitcherSheet, /select-branch, /takeaway/[shop],
  // /stores/[slug], and deep links / back-forward navigation.
  useEffect(() => {
    if (!isHydrated) return;
    const nextSlug = currentBranch.slug || null;
    if (
      shouldClearCartOnBranchChange({
        orderType,
        previousBranchSlug: previousBranchSlugRef.current,
        nextBranchSlug: nextSlug,
        itemCount: state.items.length,
      })
    ) {
      // Silent data loss from the customer's point of view — nothing in the UI
      // says the cart was emptied, so leave a trace a support ticket can use.
      console.warn(
        "[Cart] active branch changed",
        previousBranchSlugRef.current,
        "→",
        nextSlug,
        "— clearing cross-branch takeaway cart",
      );
      dispatch({ type: "CLEAR" });
      setCartMetadataState(null);
      // Also drop the persisted takeaway cart so a reload can't resurrect it.
      const storageKey = getCartStorageKey("takeaway");
      const timestampKey = getTimestampStorageKey("takeaway");
      localStorage.removeItem(storageKey);
      localStorage.removeItem(timestampKey);
      localStorage.removeItem(TAKEAWAY_CART_BRANCH_KEY);
    }
    // Only advance the ref to a *resolved* (non-empty) branch. During the
    // initial branches load `currentBranch.slug` is transiently "" — writing
    // that back would erase a seeded/known branch and miss the real switch.
    if (nextSlug) previousBranchSlugRef.current = nextSlug;

    // Maintain the durable branch key that seeds this guard on a cold load. It
    // must live and die with the *persisted* takeaway cart, independent of
    // cart metadata (which only exists when the menu has a cart deadline).
    if (orderType === "takeaway") {
      if (nextSlug && state.items.length > 0) {
        localStorage.setItem(TAKEAWAY_CART_BRANCH_KEY, nextSlug);
      } else if (state.items.length === 0) {
        localStorage.removeItem(TAKEAWAY_CART_BRANCH_KEY);
      }
    }
  }, [currentBranch.slug, orderType, isHydrated, state.items.length]);

  // Switch in-memory cart when mode changes (takeaway ↔ dine-in).
  // IMPORTANT: Do NOT delete the other mode's localStorage — each mode's
  // persisted cart must survive round-trips so the customer doesn't lose
  // items when toggling between takeaway and dine-in flows.
  useEffect(() => {
    // Skip on initial mount (before hydration)
    if (!isHydrated) return;

    const prevMode = previousOrderTypeRef.current;
    if (prevMode !== null && prevMode !== orderType) {
      // Clear in-memory cart (old mode's localStorage is intentionally preserved)
      dispatch({ type: "CLEAR" });
      setCartMetadataState(null);

      // Restore takeaway cart from localStorage when switching back
      if (orderType === 'takeaway') {
        try {
          const storageKey = getCartStorageKey('takeaway');
          const timestampKey = getTimestampStorageKey('takeaway');
          const raw = localStorage.getItem(storageKey);
          const timestamp = localStorage.getItem(timestampKey);

          // TTL check for guest users — skip restore if expired
          if (!isLoggedIn && timestamp) {
            const age = Date.now() - parseInt(timestamp, 10);
            if (age > GUEST_CART_TTL_MS) {
              localStorage.removeItem(storageKey);
              localStorage.removeItem(timestampKey);
              previousOrderTypeRef.current = orderType;
              return; // Don't restore — cart expired
            }
          }

          if (raw) {
            const parsed = JSON.parse(raw) as CartItem[];
            const valid = parsed.filter(
              (item) =>
                item &&
                typeof item.id === "string" &&
                item.product &&
                typeof item.quantity === "number" &&
                item.quantity > 0 &&
                typeof item.unitPrice === "number",
            );
            if (valid.length > 0) {
              dispatch({ type: "HYDRATE", payload: valid });
            }
          }
        } catch { /* ignore corrupt localStorage */ }
      }
    }

    previousOrderTypeRef.current = orderType;
  }, [orderType, isHydrated, isLoggedIn, dineInTable?.qr_token]);

  // Gắn deadline per-item (Option A) cho món vừa thêm.
  const addToCart = useCallback(
    async (item: CartItem) => {
      // Nếu item đã có deadline (khi hydrate từ localStorage), giữ nguyên
      if (item.itemDeadline) {
        dispatch({ type: "ADD_ITEM", payload: item });
        return;
      }

      // #1702 — card khách vừa bấm ĐÃ mang ngữ cảnh menu của chính nó
      // (`menu_id / menu_name / menu_end_time / item_deadline`, per-item từ
      // #1185). Đọc thẳng từ đó.
      //
      // Trước đây chỗ này fetch lại cả menu rồi tìm lại món theo `sku_id`. Dưới
      // view GỘP nhiều menu, một `sku_id` ứng với nhiều item khác menu khác hạn,
      // nên `.find()` trả bản ĐẦU TIÊN chứ không phải bản khách bấm: bấm card
      // お持ち帰り (đóng 21:45) thì giỏ ghi hạn của 人形町店 (22:15) — countdown
      // vẫn chạy, UI vẫn cho đặt, còn BE thì đã đóng menu đó. Vòng fetch ấy vừa
      // sai vừa thừa: câu trả lời đúng nằm sẵn trong `item.product`.
      if (item.product.item_deadline) {
        item.menuId = item.product.menu_id;
        item.menuName = item.product.menu_name;
        item.menuEndTime = item.product.menu_end_time ?? undefined;
        item.addedAt = new Date().toISOString();
        item.itemDeadline = item.product.item_deadline;
        dispatch({ type: "ADD_ITEM", payload: item });
        return;
      }

      // Không có ngữ cảnh menu — món dựng lại từ lịch sử đơn (đặt lại / mua lại)
      // chứ không phải từ card menu. Lúc đó mới phải hỏi BE.
      // Dùng `apiFetch` thay vì raw `fetch(${process.env.NEXT_PUBLIC_API_URL}…)`:
      //   • Centralize API_BASE fallback (env vắng → `http://localhost:5400`
      //     thay vì URL `"undefined/api/v1/…"` silent-fail).
      //   • Stamp đúng Accept-Language theo cookie locale (Astrotomic
      //     translatable trả đúng locale).
      //   • Cho phép silent401 — fetch enrichment best-effort, không bounce
      //     user về /login nếu unauthenticated.
      // #478 — menu-metadata enrichment must come from the SAME menu the item
      // was shown in. Backend (#463) splits menus by service_type:
      //   • dine-in  → GET tables/{qrToken}/menu   (getMenuForBranch DineIn)
      //   • takeaway → GET branches/{slug}/menu     (getMenuForBranch Takeaway)
      // Enriching a dine-in item from the Takeaway branch menu stamped the
      // wrong menuId/menuName and a Takeaway schedule_end_time → itemDeadline
      // in the past → the item dropped straight into the "Đã kết thúc" section
      // and its price mismatched (Takeaway menu vs the table's DineIn menu).
      const enrichEndpoint =
        orderType === "dine_in" && dineInTable?.qr_token
          ? `/api/v1/customer/tables/${dineInTable.qr_token}/menu`
          : currentBranch?.slug
            ? `/api/v1/customer/branches/${currentBranch.slug}/menu`
            : null;

      if (enrichEndpoint) {
        try {
          const menuJson = await apiFetch<{
            data?: {
              menu_id?: string;
              menu_name?: string;
              cart_timeout_minutes?: number;
              schedule_end_time?: string;
              categories?: MenuCategory[];
            };
          }>(enrichEndpoint, {
            silent401: true,
          });
          const data = menuJson.data || {};

          // #1185 — the menu view now MERGES several menus, so metadata is
          // per-item, not global. #1702 — tra bằng CÙNG hàm mà RECONCILE dùng
          // (lib/menu-item-match.ts): trúng `id` thì đúng dòng menu, không thì
          // mới rơi về `sku_id` và lấy bản của menu priority cao nhất. Fall back
          // to the top-level fields (single-menu, backward-compat) when the item
          // can't be located (e.g. spotlight-only edge cases).
          const { item: matched } = findMenuItem(buildMenuItemIndex(data.categories), {
            id: item.product.id,
            sku_id: item.product.sku_id,
          });

          const now = new Date();

          if (matched?.item_deadline && matched.menu_id) {
            // Per-item context from the item's own menu (#478-safe under merge).
            item.menuId = matched.menu_id;
            item.menuName = matched.menu_name;
            item.menuEndTime = matched.menu_end_time ?? undefined;
            item.addedAt = now.toISOString();
            item.itemDeadline = matched.item_deadline;
          } else if (
            typeof data.cart_timeout_minutes === "number" &&
            typeof data.schedule_end_time === "string"
          ) {
            // Legacy single-menu fallback.
            const todayStr = now.toISOString().slice(0, 10); // YYYY-MM-DD
            const menuEndMs = new Date(
              `${todayStr}T${data.schedule_end_time}`,
            ).getTime();
            const deadlineMs = menuEndMs + data.cart_timeout_minutes * 60 * 1000;

            item.menuId = data.menu_id;
            item.menuName = data.menu_name;
            item.menuEndTime = data.schedule_end_time;
            item.addedAt = now.toISOString();
            item.itemDeadline = new Date(deadlineMs).toISOString();
          }
        } catch {
          // Silent fail — không block add nếu API lỗi (best-effort enrichment)
        }
      }

      dispatch({ type: "ADD_ITEM", payload: item });
    },
    [currentBranch, orderType, dineInTable],
  );

  const removeFromCart = useCallback(
    (id: string) => dispatch({ type: "REMOVE_ITEM", payload: id }),
    [],
  );

  // Thin dispatch wrapper — stable identity (no deps) nên gọi an toàn trong
  // effect của menu-view/confirm. Logic đối chiếu nằm trong reducer (đọc
  // state.items hiện tại) để tránh stale closure.
  const reconcileCrossTimeItems = useCallback(
    (menu: ReconcilePayload) => dispatch({ type: "RECONCILE", payload: menu }),
    [],
  );

  const updateQuantity = useCallback(
    (id: string, qty: number) =>
      dispatch({ type: "UPDATE_QTY", payload: { id, qty } }),
    [],
  );

  const setDineInTable = useCallback((table: DineInTable | null) => {
    // If switching table in dine-in mode → clear cart (different table = different order session)
    if (dineInTable && table && dineInTable.qr_token !== table.qr_token) {
      // Same reasoning as the cross-branch guard above: the cart disappears
      // with no UI announcement, so record why.
      console.warn(
        '[Cart] table switched',
        dineInTable.number,
        '→',
        table.number,
        '— clearing dine-in cart',
      );
      dispatch({ type: "CLEAR" });
      setCartMetadataState(null);

      // Clear old table's cart. Dine-in lives in sessionStorage; also drop any
      // legacy localStorage copy so switching tables never leaks a stale order.
      const oldKey = getCartStorageKey("dine_in", dineInTable.qr_token);
      const oldTimestampKey = getTimestampStorageKey("dine_in", dineInTable.qr_token);
      sessionStorage.removeItem(oldKey);
      localStorage.removeItem(oldKey);
      localStorage.removeItem(oldTimestampKey);
    }

    setDineInTableState(table);
    if (table) {
      sessionStorage.setItem(DINE_IN_TABLE_KEY, JSON.stringify({ table, locked: isTableLocked }));
    } else {
      sessionStorage.removeItem(DINE_IN_TABLE_KEY);
    }
  }, [dineInTable, isTableLocked]);

  const handleSetIsTableLocked = useCallback((locked: boolean) => {
    setIsTableLocked(locked);
    if (dineInTable) {
      sessionStorage.setItem(DINE_IN_TABLE_KEY, JSON.stringify({ table: dineInTable, locked }));
    }
  }, [dineInTable]);

  const setPickupTimeData = useCallback((data: PickupTimeData) => {
    setPickupTimeDataState(data);
    localStorage.setItem(PICKUP_TIME_KEY, JSON.stringify(data));
  }, []);

  const setAppliedCouponCode = useCallback((code: string | null) => {
    setAppliedCouponCodeState(code);
  }, []);

  const clearCart = useCallback(() => {
    // Determine current cart storage keys before clearing state
    const storageKey = getCartStorageKey(orderType, dineInTable?.qr_token);
    const timestampKey = getTimestampStorageKey(orderType, dineInTable?.qr_token);

    dispatch({ type: "CLEAR" });
    setDineInTableState(null);
    setIsTableLocked(false);
    setPickupTimeDataState(DEFAULT_PICKUP_TIME);
    setCartMetadataState(null);
    setAppliedCouponCodeState(null);
    sessionStorage.removeItem(DINE_IN_TABLE_KEY);
    sessionStorage.removeItem(APPLIED_COUPON_KEY);
    sessionStorage.removeItem(storageKey); // Clear dine-in cart (sessionStorage)
    localStorage.removeItem(PICKUP_TIME_KEY);
    localStorage.removeItem(timestampKey); // Clear TTL timestamp
    localStorage.removeItem(storageKey); // Clear cart items
    localStorage.removeItem(ORDER_TYPE_KEY); // Clear order type
    localStorage.removeItem(TAKEAWAY_CART_BRANCH_KEY); // Clear cross-branch guard seed
  }, [orderType, dineInTable?.qr_token]);

  // Lightweight clear: reset items + metadata but KEEP table reference, order type,
  // and pickup time. Used after dine-in order submission so the customer can
  // continue adding items at the same table without losing context.
  const clearCartItems = useCallback(() => {
    dispatch({ type: "CLEAR" });
    setCartMetadataState(null);
  }, []);

  const clearJustAdded = useCallback(() => {
    dispatch({ type: "CLEAR_JUST_ADDED" });
  }, []);

  // #1715 — khách đã đọc thông báo "giá vừa đổi"; `null` gỡ hết.
  const acknowledgePriceAdjusted = useCallback((id: string | null = null) => {
    dispatch({ type: "ACK_PRICE_ADJUSTED", payload: id });
  }, []);

  // #1715 — áp giá server trả về trong thân 409 `line_unit_price_drift`.
  const applyServerPrices = useCallback(
    (updates: Array<{ id: string; unitPrice: number }>) =>
      dispatch({ type: "APPLY_SERVER_PRICES", payload: updates }),
    [],
  );

  const totalItems = useMemo(
    () => state.items.reduce((sum, i) => sum + i.quantity, 0),
    [state.items],
  );

  const totalPrice = useMemo(
    () => state.items.reduce((sum, i) => sum + i.unitPrice * i.quantity, 0),
    [state.items],
  );

  // Per-item timeout helpers (Option A) — each item has its own deadline
  const getItemSecondsRemaining = useCallback(
    (item: CartItem): number => {
      if (!item.itemDeadline) {
        return 0;
      }
      const deadlineMs = new Date(item.itemDeadline).getTime();
      return Math.max(0, Math.floor((deadlineMs - nowMs) / 1000));
    },
    [nowMs],
  );

  const isItemExpired = useCallback(
    (item: CartItem): boolean => {
      // Cross-time: món biến mất hoặc đổi giá ở menu mới → chặn đặt (disable
      // confirm) ngay cả khi deadline grace của nó chưa hết. Xem RECONCILE.
      if (item.priceChanged) {
        return true;
      }
      if (!item.itemDeadline) {
        return false;
      }
      return getItemSecondsRemaining(item) <= 0;
    },
    [getItemSecondsRemaining],
  );

  // Global menu timeout computed values
  const timeoutDeadline = useMemo(
    () => (cartMetadata?.deadline_iso ? new Date(cartMetadata.deadline_iso) : null),
    [cartMetadata],
  );

  const isExpired = useMemo(
    () => (timeoutDeadline ? nowMs > timeoutDeadline.getTime() : false),
    [timeoutDeadline, nowMs],
  );

  const secondsRemaining = useMemo(() => {
    if (!timeoutDeadline) return 0;
    return Math.max(0, Math.floor((timeoutDeadline.getTime() - nowMs) / 1000));
  }, [timeoutDeadline, nowMs]);

  // Lock-in semantics: once cart has items AND a deadline has been recorded,
  // subsequent calls do NOT overwrite it (menu rollover grace period).
  const setCartMetadata = useCallback(
    (metadata: CartMetadata | null) => {
      setCartMetadataState((prev) => {
        if (state.items.length > 0 && prev?.deadline_iso) {
          return prev;
        }
        return metadata;
      });
    },
    [state.items.length],
  );

  const value = useMemo<CartContextValue>(
    () => ({
      items: state.items,
      justAdded: state.justAdded,
      totalItems,
      totalPrice,
      orderType,
      setOrderType,
      dineInTable,
      setDineInTable,
      isTableLocked,
      setIsTableLocked: handleSetIsTableLocked,
      pickupTimeData,
      setPickupTimeData,
      addToCart,
      removeFromCart,
      updateQuantity,
      clearCart,
      clearCartItems,
      clearJustAdded,
      acknowledgePriceAdjusted,
      applyServerPrices,
      getItemSecondsRemaining,
      isItemExpired,
      reconcileCrossTimeItems,
      cartMetadata,
      setCartMetadata,
      timeoutDeadline,
      isExpired,
      secondsRemaining,
      appliedCouponCode,
      setAppliedCouponCode,
    }),
    [state.items, totalItems, totalPrice, state.justAdded, orderType, dineInTable, setDineInTable, isTableLocked, handleSetIsTableLocked, pickupTimeData, setPickupTimeData, addToCart, removeFromCart, updateQuantity, clearCart, clearCartItems, clearJustAdded, acknowledgePriceAdjusted, applyServerPrices, getItemSecondsRemaining, isItemExpired, reconcileCrossTimeItems, cartMetadata, setCartMetadata, timeoutDeadline, isExpired, secondsRemaining, appliedCouponCode, setAppliedCouponCode],
  );

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>;
}

export function useCart() {
  const ctx = useContext(CartContext);
  if (!ctx) throw new Error("useCart must be used within CartProvider");
  return ctx;
}
