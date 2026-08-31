import { rewriteMinioUrlsDeep } from "@/lib/image-url";
// Browser BFF: API requests are always same-origin. Next owns the local/test
// proxy and Amplify owns the production proxy. Never restore a public API base
// here: the Platform token is an HttpOnly tempo.godx.jp cookie and cannot be
// sent to a cross-origin gateway by browser JavaScript.

import { getApiLocale } from "@/lib/api-locale";

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: Record<string, unknown>
  ) {
    super((body.message as string) || `API Error ${status}`);
  }
}

/**
 * Resolve the active app locale for the current browser context. Used by
 * apiFetch to stamp Accept-Language on every request. Order:
 *   1. AppProvider's current in-memory locale
 *   2. `app_locale` localStorage entry
 *   3. readable `app_locale` cookie (SSR/browser persistence fallback)
 *   4. `"ja"` (default locale)
 *
 * Exported so tests can mock it, but production code should not call it
 * directly — every API request goes through apiFetch which already stamps
 * the header. If you find yourself importing this to stamp Accept-Language
 * manually, you're bypassing apiFetch and introducing drift; use apiFetch
 * with the `responseType` / `silent401` options instead.
 */
export function getLocale(): string {
  if (typeof document === "undefined") return "ja";
  const activeLocale = getApiLocale();
  if (activeLocale) return activeLocale;
  const storedLocale = localStorage.getItem("app_locale");
  if (storedLocale) return storedLocale;
  const match = document.cookie.match(/(?:^|;\s*)app_locale=([^;]+)/);
  if (match) return decodeURIComponent(match[1]);
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
   * for CSV / file downloads, `"text"` for plaintext, `"raw"` to get the
   * Response object back untouched (useful for streaming).
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

const FETCH_TIMEOUT_MS = 10_000;

// Overloads let TypeScript pick the right return type based on responseType
// without callers needing explicit generics or type assertions.
export async function apiFetch<T>(
  path: string,
  options?: ApiFetchOptions & { responseType?: "json" }
): Promise<T>;
export async function apiFetch(
  path: string,
  options: ApiFetchOptions & { responseType: "blob" }
): Promise<Blob>;
export async function apiFetch(
  path: string,
  options: ApiFetchOptions & { responseType: "text" }
): Promise<string>;
export async function apiFetch(
  path: string,
  options: ApiFetchOptions & { responseType: "raw" }
): Promise<Response>;
export async function apiFetch(path: string, options: ApiFetchOptions = {}): Promise<unknown> {
  const { responseType = "json", silent401 = false, headers, body, signal, ...rest } = options;

  const timeoutSignal = AbortSignal.timeout(FETCH_TIMEOUT_MS);
  const mergedSignal = signal ? AbortSignal.any([signal, timeoutSignal]) : timeoutSignal;

  const locale = getLocale();

  // Content-Type handling: default to application/json, but let the browser
  // set its own boundary-carrying header when the body is FormData — hard-
  // coding application/json there breaks the upload.
  const isFormData = typeof FormData !== "undefined" && body instanceof FormData;
  const defaultAccept =
    responseType === "blob" || responseType === "text" ? "*/*" : "application/json";

  const mergedHeaders = new Headers({
    Accept: defaultAccept,
    "Accept-Language": locale,
  });
  if (!isFormData) {
    mergedHeaders.set("Content-Type", "application/json");
  }
  // Caller-supplied headers win — so a caller can still override Accept,
  // Content-Type, Accept-Language, etc. when they genuinely need to.
  if (headers) {
    const h = new Headers(headers);
    h.forEach((value, key) => {
      mergedHeaders.set(key, value);
    });
  }

  const response = await fetch(path, {
    ...rest,
    credentials: "include",
    signal: mergedSignal,
    headers: mergedHeaders,
    body,
  });

  if (!response.ok) {
    // Restart the server-side Platform flow when the session is invalid.
    if (response.status === 401 && !silent401 && typeof window !== "undefined") {
      const returnTo = window.location.pathname + window.location.search;
      window.location.assign(`/auth/redirect?return=${encodeURIComponent(returnTo)}`);
      return new Promise(() => {}); // hang until redirect
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

export interface PaginatedResponse<T> {
  data: T[];
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
  };
}
