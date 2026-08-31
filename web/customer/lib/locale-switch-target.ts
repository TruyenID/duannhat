/**
 * godx-tempo#1773 — đích điều hướng khi khách đổi ngôn ngữ.
 *
 * Switcher trước đây truyền thẳng `usePathname()` vào `router.replace`, mà hàm
 * đó của next-intl chỉ trả về ĐƯỜNG DẪN. Query string bị bỏ, và đó không phải
 * chuyện thẩm mỹ: `/order-success` đọc TOÀN BỘ trạng thái từ query — `id`,
 * `code`, `type`, `shop`, và `stripe_return`. Riêng `stripe_return=1` là cờ
 * "đơn này đã trả xong". Mất nó thì màn hình rơi về nhánh chưa-thu-tiền, nên
 * khách vừa trả tiền xong, bấm đổi ngôn ngữ, liền được báo là ĐANG CHỜ THANH
 * TOÁN và mất luôn mã đơn cần mang ra quầy.
 *
 * Đúng cái kịch bản mà comment ở `app/[locale]/orders/[id]/pay/page.tsx` đã
 * cảnh báo khi giải thích vì sao phải gắn cờ này: "lời mời trả lần hai". Và
 * người bấm nút đổi ngôn ngữ chính là khách nước ngoài — nhóm dễ hoảng nhất.
 *
 * Các trang khác cũng mất: `/orders?tab=pending` (link "Chưa thanh toán" trên
 * header trỏ thẳng vào đây) và `/checkout?repick=pickup` (cờ một lần từ
 * /order-confirm để hiện lỗi giờ hẹn ngay trên picker).
 */

/**
 * Ghép lại đường dẫn + query + hash thành đích cho `router.replace`.
 *
 * Tách riêng khỏi component vì đây là chỗ đã hỏng một lần và không có gì canh:
 * lỗi im lặng, không log, không lỗi console, chỉ khách nhìn thấy.
 *
 * @param pathname đường dẫn KHÔNG kèm locale prefix (`usePathname()` của next-intl)
 * @param search   `window.location.search` — có hoặc không có dấu `?` đều được
 * @param hash     `window.location.hash` — có hoặc không có dấu `#` đều được
 */
export function localeSwitchTarget(
  pathname: string,
  search?: string | null,
  hash?: string | null,
): string {
  const query = normalize(search, "?");
  const fragment = normalize(hash, "#");

  return `${pathname}${query}${fragment}`;
}

/**
 * Trả về "" cho rỗng, ngược lại đảm bảo đúng một ký tự dẫn đầu.
 *
 * `window.location.search` là "" khi không có tham số, nhưng một caller truyền
 * "?" trơ (query rỗng) cũng phải ra "" — nếu không thì URL mọc thêm dấu "?"
 * thừa sau mỗi lần đổi ngôn ngữ.
 */
function normalize(value: string | null | undefined, prefix: "?" | "#"): string {
  if (!value) return "";

  const body = value.startsWith(prefix) ? value.slice(1) : value;

  return body.length > 0 ? `${prefix}${body}` : "";
}

/**
 * #1777 — cookie ghi lựa chọn ngôn ngữ.
 *
 * Hằng số này phải dùng CHUNG giữa bên ghi (switcher) và bên đọc
 * (`LocaleGuard`), vì hai bên bất đồng thì hỏng theo kiểu vòng lặp chứ không
 * phải kiểu quên: switcher mobile trước đây điều hướng mà KHÔNG ghi cookie, nên
 * `LocaleGuard` thấy cookie ≠ locale trên URL và lập tức `router.replace` ngược
 * lại. Khách bấm sang tiếng Anh, màn hình nháy về tiếng Việt — và cú replace
 * ngược đó cắt query THÊM một lần nữa.
 */
export const LOCALE_COOKIE = "NEXT_LOCALE";

const ONE_YEAR_SECONDS = 60 * 60 * 24 * 365;

/** Chuỗi gán cho `document.cookie`. Tách ra để test được mà không cần DOM. */
export function localeCookieHeader(locale: string): string {
  return `${LOCALE_COOKIE}=${locale}; path=/; max-age=${ONE_YEAR_SECONDS}; SameSite=Lax`;
}

/**
 * Đọc locale đã ghi từ `document.cookie`. Trả `null` khi chưa có.
 *
 * Không tách bằng `split("; ")`: dấu cách sau `;` là quy ước chứ không phải
 * ràng buộc, và `LocaleGuard` từng đọc theo kiểu đó. Cookie duy nhất hoặc
 * cookie do một thư viện khác ghi mà không có dấu cách thì guard đọc hụt, coi
 * như "chưa chọn ngôn ngữ".
 */
export function readLocaleCookie(cookieString: string | null | undefined): string | null {
  if (!cookieString) return null;

  const match = cookieString.match(new RegExp(`(?:^|;\\s*)${LOCALE_COOKIE}=([^;]*)`));

  return match && match[1] ? decodeURIComponent(match[1]) : null;
}
