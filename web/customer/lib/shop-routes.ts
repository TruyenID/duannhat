/**
 * Khu tài khoản chỉ tồn tại BÊN TRONG một cửa hàng (#1505).
 *
 * `/login`, `/register`, `/account/*` đều phải mang slug cửa hàng trong URL —
 * `/login/{shop}`, `/register/{shop}`, `/account/{shop}/orders`. Lý do là
 * nghiệp vụ chứ không phải thẩm mỹ URL: khách tự đăng ký trước đây luôn rơi
 * vào `customers.branch_id = NULL`, nên không quy được khách về cửa hàng nào.
 * Slug trong URL là thứ duy nhất đi cùng request đăng ký một cách chắc chắn —
 * chi nhánh "đang chọn" trong localStorage thì một tab khác đổi lúc nào cũng
 * được.
 *
 * Module này CỐ Ý thuần tuý (không React, không `next/*`) vì middleware chạy
 * ở edge runtime và phải import được nó, còn `node --test` thì chạy thẳng.
 */

/** Các nhánh route chỉ mở khi URL mang slug cửa hàng. */
export const SHOP_SCOPED_PREFIXES = ['/login', '/register', '/account'] as const;

export type ShopScopedPrefix = (typeof SHOP_SCOPED_PREFIXES)[number];

/** Giá trị `?next=` mà /select-branch hiểu, cho nhóm route cần cửa hàng. */
export type ShopScopedFlow = 'login' | 'register' | 'account';

/**
 * Path nằm trong khu cần cửa hàng nhưng KHÔNG có segment slug.
 *
 * Nhận pathname đã bỏ locale prefix (`/vi/login` → `/login`). Trả về prefix
 * thiếu slug, hoặc null nếu không phải việc của guard.
 *
 * Chỉ bắt đúng trường hợp khớp CHÍNH XÁC prefix. `/account/orders` — URL cũ từ
 * bookmark — vẫn lọt qua đây với "orders" đóng vai slug; chặn nó ở middleware
 * bằng một danh sách tên route cũ sẽ đẻ ra vòng lặp redirect vào đúng ngày ai
 * đó đặt slug chi nhánh trùng một tên trong danh sách. Việc đối chiếu slug với
 * danh sách chi nhánh có thật thuộc về trang (middleware ở edge không gọi API
 * được) — xem `components/require-shop.tsx`.
 */
export function missingShopSegment(pathnameWithoutLocale: string): ShopScopedPrefix | null {
  const path = stripTrailingSlash(pathnameWithoutLocale);
  return SHOP_SCOPED_PREFIXES.find((prefix) => path === prefix) ?? null;
}

/** `/login` → `login`. Dùng để dựng `?next=` khi đá về /select-branch. */
export function flowForPrefix(prefix: ShopScopedPrefix): ShopScopedFlow {
  return prefix.slice(1) as ShopScopedFlow;
}

/** URL /select-branch mang theo ý định ban đầu, để chọn xong quay lại đúng khu. */
export function selectBranchHref(next: ShopScopedFlow): string {
  return `/select-branch?next=${next}`;
}

/**
 * Đích sau khi khách chọn cửa hàng ở /select-branch.
 *
 * `sub` chỉ dùng cho khu account (`orders`, `points`…). Không nhận sub cho
 * login/register vì hai khu đó không có trang con.
 */
export function shopScopedHref(flow: ShopScopedFlow, slug: string, sub?: string): string {
  const base = `/${flow}/${slug}`;
  return sub ? `${base}/${trimSlashes(sub)}` : base;
}

/**
 * Href tới trang đăng nhập của một cửa hàng.
 *
 * Chưa biết cửa hàng thì trả href /select-branch — link vẫn bấm được và khách
 * chọn cửa hàng ở đó, thay vì trỏ vào một URL mà middleware sẽ đá ra.
 */
export function loginHref(slug?: string | null): string {
  return slug ? shopScopedHref('login', slug) : selectBranchHref('login');
}

/** Href tới trang đăng ký của một cửa hàng. Xem {@link loginHref}. */
export function registerHref(slug?: string | null): string {
  return slug ? shopScopedHref('register', slug) : selectBranchHref('register');
}

/** Href tới khu tài khoản của một cửa hàng. `sub` là đường dẫn con, vd `orders`. */
export function accountHref(slug?: string | null, sub?: string): string {
  return slug ? shopScopedHref('account', slug, sub) : selectBranchHref('account');
}

/**
 * Khoá localStorage giữ chi nhánh khách đang chọn.
 *
 * Sống ở module thuần này (chứ không ở `brand-context`) để phía không-React —
 * `lib/api.ts`, nơi xử lý 401 toàn cục — đọc được mà không phải kéo cả context
 * vào đồ thị import.
 */
export const SELECTED_BRANCH_STORAGE_KEY = 'betoya-selected-branch';

/** Các segment route mà segment ngay sau nó là slug cửa hàng. */
const SHOP_BEARING_SEGMENTS = new Set([
  'login',
  'register',
  'account',
  'takeaway',
  'dine-in',
  'stores',
]);

/**
 * Rút slug cửa hàng ra khỏi một pathname bất kỳ (còn nguyên locale prefix).
 *
 * Dùng cho đường 401 toàn cục: khách đang ở `/vi/account/ginza/orders` mà phiên
 * hết hạn thì phải quay lại trang đăng nhập CỦA ginza, không phải rơi ra
 * /select-branch và mất luôn ngữ cảnh mình vừa đứng.
 */
export function shopSlugFromPathname(pathname: string): string | null {
  const parts = pathname.split('/').filter(Boolean);
  for (let i = 0; i < parts.length - 1; i++) {
    if (SHOP_BEARING_SEGMENTS.has(parts[i])) {
      return parts[i + 1];
    }
  }
  return null;
}

/**
 * Cửa hàng hiện tại theo góc nhìn của code không-React: URL trước, rồi mới tới
 * lựa chọn đã lưu. URL thắng vì nó là cái khách đang nhìn thấy.
 */
export function currentShopSlug(): string | null {
  if (typeof window === 'undefined') return null;
  const fromPath = shopSlugFromPathname(window.location.pathname);
  if (fromPath) return fromPath;
  try {
    return window.localStorage.getItem(SELECTED_BRANCH_STORAGE_KEY);
  } catch {
    return null;
  }
}

/**
 * Route mà cửa hàng đến từ DỮ LIỆU của trang chứ không từ URL: giỏ hàng và đơn
 * đều mang sẵn branch, nên entry-point khu tài khoản ở đây vẫn quy được về
 * đúng cửa hàng dù URL trần.
 *
 * `/checkout` nằm trong đây có chủ ý — đó là chỗ khách cần đăng nhập để dùng
 * coupon mà backend trả `error_code: 'customer_required'`.
 */
export const CART_SCOPED_SEGMENTS = new Set([
  'checkout',
  'order-confirm',
  'order-success',
]);

/**
 * Có được hiện link/nút dẫn vào khu tài khoản trên path này không (#1717).
 *
 * #1505 chặn được *route* nhưng vẫn để nút Đăng nhập/Đăng ký hiện trên URL
 * không mang cửa hàng, vì các call-site dựng href từ `currentBranch` — tức
 * localStorage. Đó đúng là thứ #1505 nói không đáng tin: khách bấm Đăng ký ở
 * trang chủ sẽ bị quy về một chi nhánh họ không hề chọn trong phiên này, tái
 * lập đúng cái sai mà #1505 sinh ra để chặn.
 *
 * Ranh giới KHÔNG phải "URL có slug hay không" mà là "cửa hàng có được xác
 * định chắc chắn hay không": URL mang slug, hoặc trang thuộc luồng mua nơi
 * giỏ/đơn đã ghim branch. Trang cấp thương hiệu (`/`, `/menus`, `/orders`,
 * `/select-branch`…) không có cửa hàng nào ⇒ không có entry-point.
 *
 * Nhận pathname có hoặc không có locale prefix đều được — hàm quét theo segment
 * chứ không đếm vị trí.
 */
export function authEntryPointsAllowed(pathname: string): boolean {
  if (shopSlugFromPathname(pathname) !== null) return true;
  return pathname
    .split('/')
    .filter(Boolean)
    .some((segment) => CART_SCOPED_SEGMENTS.has(segment));
}

/** Ô tài khoản ở header đang phải hiện cái gì. Xem {@link headerAuthSlot}. */
export type HeaderAuthSlot = 'chip' | 'guest-cta' | 'none';

export interface HeaderAuthSlotArgs {
  /** `FEATURES.auth` — cả khu tài khoản còn tắt được bằng flag (#47). */
  featureAuthEnabled: boolean;
  /**
   * `FEATURES.authEntryPoints` — có được MỜI khách vãng lai đăng nhập không.
   *
   * Chỉ chi phối nhánh `guest-cta`. Người đã đăng nhập vẫn ra `chip`: tắt lời
   * mời không có nghĩa là giấu luôn tài khoản của người đang dùng nó.
   */
  guestCtaEnabled: boolean;
  isLoggedIn: boolean;
  /** Có hoặc không có locale prefix đều được. */
  pathname: string;
}

/**
 * Quyết định DUY NHẤT cho ô tài khoản ở header (#1747).
 *
 * `chip` = avatar + tên người đang đăng nhập; `guest-cta` = nút Đăng nhập/Đăng
 * ký; `none` = không có gì (header rơi về language switcher).
 *
 * **Đăng nhập KHÔNG phải đường vòng qua cổng cửa hàng.** #1717 ẩn nút Đăng
 * nhập/Đăng ký trên URL không xác định được cửa hàng, nhưng vẫn cho chip hiện ở
 * mọi nơi khi đã đăng nhập — mà chip cũng là một đường VÀO khu tài khoản, và
 * href của nó cũng dựng từ cửa hàng. Nên cả hai đi chung một cổng: khách là
 * khách CỦA MỘT CỬA HÀNG, trang cấp thương hiệu (`/`, `/menus`, `/orders`,
 * `/select-branch`…) không quy được về cửa hàng nào.
 *
 * Ẩn chip không nhốt ai trong phiên: chip không chứa nút Đăng xuất (nó nằm ở
 * trang tài khoản, và ở hamburger mobile — mục đó vẫn gate theo `isLoggedIn`
 * chứ không theo hàm này).
 */
export function headerAuthSlot({
  featureAuthEnabled,
  guestCtaEnabled,
  isLoggedIn,
  pathname,
}: HeaderAuthSlotArgs): HeaderAuthSlot {
  if (!featureAuthEnabled) return 'none';
  if (!authEntryPointsAllowed(pathname)) return 'none';
  if (isLoggedIn) return 'chip';
  return guestCtaEnabled ? 'guest-cta' : 'none';
}

function stripTrailingSlash(path: string): string {
  return path.length > 1 && path.endsWith('/') ? path.slice(0, -1) : path;
}

function trimSlashes(segment: string): string {
  return segment.replace(/^\/+|\/+$/g, '');
}
