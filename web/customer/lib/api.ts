import { rewriteMinioUrlsDeep } from "@/lib/image-url";
import { FEATURES } from "@/lib/feature-flags";
import { resolveApiBase } from "./api-base";
import { readAuthToken } from "./auth-token";
import { currentShopSlug, loginHref } from "./shop-routes";
import { readLocaleCookie } from "./locale-switch-target";

export const API_BASE = resolveApiBase(
  process.env.NEXT_PUBLIC_API_URL,
  process.env.NODE_ENV,
  typeof window !== "undefined",
);

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: Record<string, unknown>,
  ) {
    super((body.message as string) || `API Error ${status}`);
    this.name = "ApiError";
  }
}

function getToken(): string | null {
  // FEATURES.auth off → mọi request đi ở chế độ guest, kể cả khi trình duyệt
  // còn token cũ từ phiên trước lúc auth vẫn bật.
  if (!FEATURES.auth) return null;
  // Chỗ cất token do "Ghi nhớ đăng nhập" quyết định (localStorage hay
  // sessionStorage) — `lib/auth-token.ts` là nơi duy nhất biết điều đó (#1781).
  return readAuthToken();
}

/**
 * Resolve the active app locale for the current browser context. Used by
 * apiFetch to stamp Accept-Language on every request. Order:
 *   1. `<html lang>` attribute (set by next-intl layout)
 *   2. locale cookie (set by next-intl middleware and by the language
 *      switcher — tên cookie do `lib/locale-switch-target.ts` giữ, #1777)
 *   3. `app_locale` cookie / localStorage (legacy)
 *   4. `"ja"` (default locale)
 */
export function getLocale(): string {
  if (typeof document === "undefined") return "ja";
  // next-intl sets <html lang="xx"> on every render — most reliable source
  const htmlLang = document.documentElement.lang;
  if (htmlLang) return htmlLang;
  // next-intl middleware cookie
  const nextLocale = readLocaleCookie(document.cookie);
  if (nextLocale) return nextLocale;
  // legacy admin-web compat
  const appLocale = document.cookie.match(/(?:^|;\s*)app_locale=([^;]+)/);
  if (appLocale) return decodeURIComponent(appLocale[1]);
  const stored = localStorage.getItem("app_locale");
  if (stored) return stored;
  return "ja";
}

/**
 * Options accepted by apiFetch beyond the standard fetch RequestInit.
 * Every call site in the app goes through this function so auth / locale /
 * 401 handling stays in one place.
 */
export interface ApiFetchOptions extends Omit<RequestInit, "body"> {
  body?: BodyInit | null;
  /**
   * How to interpret the response body. Defaults to `"json"`. Use `"blob"`
   * for file downloads, `"text"` for plaintext, `"raw"` to get the
   * Response object back untouched.
   */
  responseType?: "json" | "blob" | "text" | "raw";
  /**
   * When true, a 401 response throws an ApiError instead of redirecting
   * the browser to /login. Use for fire-and-forget calls (preference
   * persistence, heartbeats) where bouncing the user mid-flow is worse
   * than silently dropping the update.
   */
  silent401?: boolean;
}

export async function apiFetch<T>(
  path: string,
  options?: ApiFetchOptions & { responseType?: "json" },
): Promise<T>;
export async function apiFetch(
  path: string,
  options: ApiFetchOptions & { responseType: "blob" },
): Promise<Blob>;
export async function apiFetch(
  path: string,
  options: ApiFetchOptions & { responseType: "text" },
): Promise<string>;
export async function apiFetch(
  path: string,
  options: ApiFetchOptions & { responseType: "raw" },
): Promise<Response>;
export async function apiFetch(
  path: string,
  options: ApiFetchOptions = {},
): Promise<unknown> {
  const { responseType = "json", silent401 = false, headers, body, ...rest } = options;

  const token = getToken();
  const locale = getLocale();

  const isFormData = typeof FormData !== "undefined" && body instanceof FormData;
  const defaultAccept =
    responseType === "blob" || responseType === "text" ? "*/*" : "application/json";

  const mergedHeaders: Record<string, string> = {
    Accept: defaultAccept,
    "Accept-Language": locale,
    ...(token && { Authorization: `Bearer ${token}` }),
  };
  if (!isFormData) {
    mergedHeaders["Content-Type"] = "application/json";
  }
  if (headers) {
    const h = new Headers(headers);
    h.forEach((value, key) => {
      mergedHeaders[key] = value;
    });
  }

  const response = await fetch(`${API_BASE}${path}`, {
    ...rest,
    headers: mergedHeaders,
    body,
  });

  if (!response.ok) {
    if (response.status === 401 && !silent401 && typeof window !== "undefined") {
      // Auth off: `/login` bị middleware redirect về `/` → gửi thẳng về `/`
      // để tránh một hop thừa (và tránh vòng lặp nếu guard đổi về sau).
      //
      // Auth on: trang đăng nhập phải mang cửa hàng (#1505). Không biết
      // cửa hàng nào thì `loginHref` tự trỏ /select-branch — vẫn là một
      // trang dùng được, khác hẳn `/login` trần vốn bị guard đá ra.
      window.location.href = FEATURES.auth ? loginHref(currentShopSlug()) : "/";
      return new Promise(() => {});
    }
    const errBody = await response.json().catch(() => ({}));
    throw new ApiError(response.status, errBody);
  }

  if (response.status === 204) return null;

  switch (responseType) {
    case "blob":
      return response.blob();
    case "text":
      return response.text();
    case "raw":
      return response;
    case "json":
    default:
      return rewriteMinioUrlsDeep(await response.json());
  }
}
