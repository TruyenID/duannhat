/**
 * Nơi DUY NHẤT quyết định token của khách nằm ở đâu (#1781).
 *
 * Ô "Ghi nhớ đăng nhập" ở trang login chỉ đổi được đúng một thứ: CHỖ CẤT token.
 * Tick → `localStorage`, phiên sống qua lần đóng trình duyệt (hành vi cũ, và
 * vẫn là mặc định). Bỏ tick → `sessionStorage`, đóng tab là mất phiên. Token
 * Sanctum không có TTL, backend cũng không có khái niệm "remember", nên đó là
 * toàn bộ ý nghĩa khả dĩ của ô đó — đừng hứa với khách nhiều hơn thế.
 *
 * `lib/api.ts` (gắn Bearer vào mọi request) và `context/auth-context.tsx`
 * (khôi phục phiên lúc mount) BẮT BUỘC đọc qua đây. Hai chỗ tự gọi thẳng
 * `localStorage` là đường ra đúng một bug: gọi API thì mất token trong khi UI
 * vẫn tưởng đang đăng nhập.
 */
export const AUTH_TOKEN_STORAGE_KEY = "cw_auth_token";

/** Token đang có, bất kể được cất bằng chế độ nào. */
export function readAuthToken(): string | null {
  if (typeof window === "undefined") return null;
  return (
    window.localStorage.getItem(AUTH_TOKEN_STORAGE_KEY) ??
    window.sessionStorage.getItem(AUTH_TOKEN_STORAGE_KEY)
  );
}

/**
 * @param remember `true` = ghi nhớ đăng nhập (localStorage), `false` = chỉ
 *   sống trong tab hiện tại (sessionStorage).
 */
export function writeAuthToken(token: string, remember: boolean): void {
  if (typeof window === "undefined") return;
  const [target, other] = remember
    ? [window.localStorage, window.sessionStorage]
    : [window.sessionStorage, window.localStorage];
  // Xoá chỗ KIA trước khi ghi. Bỏ bước này thì một lần đăng nhập "không ghi
  // nhớ" ngay sau một lần "có ghi nhớ" sẽ để token cũ nằm lại localStorage:
  // `readAuthToken` ưu tiên localStorage nên phiên vẫn sống sau khi đóng trình
  // duyệt — đúng cái khách vừa từ chối.
  other.removeItem(AUTH_TOKEN_STORAGE_KEY);
  target.setItem(AUTH_TOKEN_STORAGE_KEY, token);
}

/** Đăng xuất / token bị backend từ chối — dọn cả hai chỗ. */
export function clearAuthToken(): void {
  if (typeof window === "undefined") return;
  window.localStorage.removeItem(AUTH_TOKEN_STORAGE_KEY);
  window.sessionStorage.removeItem(AUTH_TOKEN_STORAGE_KEY);
}
