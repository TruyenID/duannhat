/**
 * #1501 — CÁI GÌ ĐƯỢC PHÉP NẰM TRONG CACHE ĐỌC OFFLINE.
 *
 * Đây là ranh giới tiền của tầng 2, viết thành code chứ không thành lời hứa.
 *
 * ## Danh sách CHO PHÉP, không phải danh sách cấm
 *
 * `isCacheableQueryKey` mặc định trả `false`. Một query key mới xuất hiện —
 * domain mới, refactor đổi tên root — **không** tự động được cache. Nếu quy
 * tắc là danh sách cấm thì một `["payouts", …]` thêm vào tuần sau sẽ lặng lẽ
 * rơi vào IndexedDB, và không có gì đỏ.
 *
 * ## Vì sao đường tiền bị loại
 *
 * pos-web là màn thu ngân trong TRÌNH DUYỆT. Bán offline đáng tin cần chữ ký
 * Ed25519 + catalog revision + Cloud re-price (#1092) và đó là vai của
 * workstation-app — cố ý không nhân bản sang một tab trình duyệt dùng chung.
 * Nên ở đây, mọi thứ mà một con số cũ có thể biến thành tiền thật đều bị loại:
 *
 *   - `orders` / `order-payments` — **tổng tiền đơn**. Thu ngân đọc to con số
 *     trên màn hình rồi nhận tiền mặt; con số đó cũ 40 phút là thu sai.
 *   - `payment-methods` / `effective-payment-options` — phương thức bị tắt ở
 *     Cloud vẫn hiện ra thì thu ngân chọn một cửa không còn tồn tại.
 *   - `till` — ca thu ngân, mệnh giá, đối soát, 過不足. Toàn bộ plan-030/046.
 *   - `revenue` — báo cáo doanh thu.
 *   - `customer-outstanding` — công nợ khách.
 *
 * Cái được phép là **dữ liệu tham chiếu**: thực đơn, sơ đồ bàn, lý do huỷ,
 * cấu hình đơn/thuế của cửa hàng. Giá trong thực đơn có phải "tiền" không? Là
 * giá niêm yết, không phải số tiền phải thu — và vì **không tạo được đơn khi
 * mất mạng** (mọi mutation đi qua `apiFetch`), một giá cũ hiển thị offline
 * không có đường nào thành tiền. Bảng bàn (`TableResource`) không mang số tiền
 * nào; đã kiểm ở `src/app/pos/types.ts`.
 *
 * Ở tầng service worker mọi `/api/**` vẫn là `NetworkOnly` (tầng 1, #1170) —
 * cache này nằm ở TẦNG ỨNG DỤNG, có chủ đích và đọc được, không phải cache
 * HTTP ngầm.
 */
import { hashKey } from "@tanstack/react-query";

/**
 * Root segment (phần tử [0] của query key) được phép ghi xuống IndexedDB.
 *
 * `"shop"` phủ cả `shopKeys.detail` lẫn `shopOrderSettingsKeys.get`
 * (`["shop", slug, "settings", "order"]`) — cấu hình đơn + ngữ cảnh thuế mà
 * issue yêu cầu. `till` là root RIÊNG (`["till", slug, …]`), không nằm dưới
 * `shop`, nên cho phép `shop` không kéo theo ca thu ngân.
 */
export const CACHEABLE_QUERY_ROOTS = [
  "shop",
  "shop-menus",
  // plan-056 — màn "Tồn món". Cùng loại dữ liệu với `shop-menus` (danh mục
  // món, không phải tiền), nhưng là ROOT RIÊNG vì nó mang cả món ĐANG TẮT còn
  // `shop-menus` thì không — trộn hai cái vào một root là để một lượt hydrate
  // của màn quản lý trả lời cho màn bán.
  //
  // Cache ở đây có ích thật: quán mất mạng ở chế độ cloud vẫn ĐỌC được menu để
  // biết món nào đang tắt. Ghi thì vẫn hỏng và báo lỗi — đúng như vậy, vì một
  // thay đổi danh mục phát lại muộn dễ đè lên sự thật mới hơn.
  "menu-availability",
  "tables",
  "void-reasons",
  "floating-sections",
] as const;

/**
 * Đường tiền — KHÔNG BAO GIỜ được cache.
 *
 * Về mặt kỹ thuật danh sách này là thừa (allowlist đã loại rồi). Nó tồn tại
 * để `offline-cache-policy.test.ts` khẳng định thẳng vào từng root một, nên
 * khi có người thêm `"orders"` vào allowlist thì test đỏ kèm tên cụ thể chứ
 * không phải một lỗi mơ hồ.
 */
export const MONEY_QUERY_ROOTS = [
  "orders",
  "order-payments",
  "payment-methods",
  "effective-payment-options",
  "till",
  "revenue",
  "customer-outstanding",
  // An outstanding balance is a figure the cashier says out loud before taking
  // the customer's money. Forty minutes stale is the wrong number collected.
  "debts",
] as const;

const CACHEABLE = new Set<string>(CACHEABLE_QUERY_ROOTS);
const MONEY = new Set<string>(MONEY_QUERY_ROOTS);

export function isMoneyQueryRoot(root: unknown): boolean {
  return typeof root === "string" && MONEY.has(root);
}

/** Query key này có được phép rơi vào IndexedDB không? Mặc định: KHÔNG. */
export function isCacheableQueryKey(queryKey: readonly unknown[]): boolean {
  const root = queryKey[0];
  if (typeof root !== "string") return false;
  // Hai lớp: allowlist là luật, dòng này là cái chuông. Nếu ai đó thêm một
  // root tiền vào allowlist thì nó vẫn không lọt được ra runtime.
  if (MONEY.has(root)) return false;
  return CACHEABLE.has(root);
}

/**
 * Khoá lưu trữ = ĐÚNG hàm băm của TanStack Query (`hashKey`), không phải một
 * `JSON.stringify` tự viết.
 *
 * `hashKey` sắp xếp khoá của object trước khi stringify, nên `{a:1,b:2}` và
 * `{b:2,a:1}` — cùng một query với TanStack — cũng là cùng một bản ghi ở đây.
 * Tự viết stringify thì hai object đó thành hai bản ghi, và bản cũ hơn sẽ
 * thắng ở lần hydrate sau tuỳ thứ tự đọc.
 */
export function queryCacheKey(queryKey: readonly unknown[]): string {
  return hashKey(queryKey);
}
