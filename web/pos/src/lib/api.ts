import { clearSession, getToken as getStoredToken } from "./auth";
import { getCurrentShopSlug } from "./shop-context";
import {
  CLOUD_URL,
  getMode,
  markWorkstationReachable,
  markWorkstationUnreachable,
  resolveBaseUrl,
} from "@/services/workstation/base-url-resolver";
import { recordRequest } from "@/services/workstation/request-stats";
import { markApiOutcome } from "./network-status";
import { LOCALE_STORAGE_KEY } from "@/i18n";

export class ApiError extends Error {
  status: number;
  body: Record<string, unknown>;
  /**
   * Laravel validation envelope `{message, errors: {field: [msgs]}}` — the
   * per-field messages that used to drop silently (#284 hardening). Empty
   * object for non-validation errors.
   */
  fieldErrors: Record<string, string[]>;

  constructor(status: number, body: Record<string, unknown>) {
    super(ApiError.messageFrom(status, body));
    this.status = status;
    this.body = body;
    this.fieldErrors = ApiError.fieldErrorsFrom(body);
  }

  /** First per-field message, if any — the most actionable line for a toast. */
  firstFieldError(): string | null {
    for (const messages of Object.values(this.fieldErrors)) {
      if (messages.length > 0) return messages[0];
    }
    return null;
  }

  /**
   * Pick the most human-actionable message the envelope carries:
   *  1. Laravel validation → the FIRST per-field message ("The amount must
   *     be at least 1.") instead of the generic top-level "The given data
   *     was invalid.".
   *  2. `{message}` — the Tempo/Laravel default shape.
   *  3. RFC 7807 problem+json (`detail` then `title`) — the gen-2 KDS
   *     surface ships this; adopting it here was a tracked follow-up.
   */
  private static messageFrom(status: number, body: Record<string, unknown>): string {
    const fieldErrors = ApiError.fieldErrorsFrom(body);
    for (const messages of Object.values(fieldErrors)) {
      if (messages.length > 0) return messages[0];
    }
    if (typeof body.message === "string" && body.message !== "") return body.message;
    if (typeof body.detail === "string" && body.detail !== "") return body.detail;
    if (typeof body.title === "string" && body.title !== "") return body.title;
    return `API Error ${status}`;
  }

  private static fieldErrorsFrom(body: Record<string, unknown>): Record<string, string[]> {
    const errors = body.errors;
    if (typeof errors !== "object" || errors === null || Array.isArray(errors)) return {};

    const out: Record<string, string[]> = {};
    for (const [field, value] of Object.entries(errors as Record<string, unknown>)) {
      if (Array.isArray(value)) {
        const messages = value.filter((m): m is string => typeof m === "string");
        if (messages.length > 0) out[field] = messages;
      } else if (typeof value === "string") {
        out[field] = [value];
      }
    }
    return out;
  }
}

/**
 * A write reached fetch but no authoritative HTTP response came back. The
 * server may have committed before the browser timeout/connection loss, so the
 * only safe next step is to read the resource and reconcile its state.
 */
export class AmbiguousMutationError extends Error {
  readonly code = "MUTATION_OUTCOME_UNKNOWN";
  readonly delivery = "unknown";
  readonly reconcileRequired = true;
  readonly method: string;
  readonly path: string;
  readonly originalError: unknown;

  constructor(
    method: string,
    path: string,
    locale: string,
    originalError: unknown,
  ) {
    const language = locale.toLowerCase().split("-")[0];
    const message =
      language === "vi"
        ? "Không xác định thao tác đã được xử lý hay chưa. Hệ thống đã dừng tự động gửi lại; hãy tải lại dữ liệu để đối soát trước khi thử lại."
        : language === "ja"
          ? "処理が完了したか確認できません。自動再送を停止しました。再試行する前にデータを再読み込みして確認してください。"
          : "The operation may already have completed. Automatic replay was stopped; reload and reconcile before trying again.";
    super(message);
    this.name = "AmbiguousMutationError";
    this.method = method;
    this.path = path;
    this.originalError = originalError;
  }
}

/**
 * Handler registry for 401 (unauthorized) responses. AuthProvider registers
 * a callback on mount that clears auth state, clears the query cache, and
 * navigates to /pairing via the router (no hard reload).
 *
 * Module-level state — apiFetch is a plain async function with no React
 * context. Callback registry avoids coupling api.ts to AuthProvider.
 */
let onUnauthorized: (() => void) | null = null;

export function setUnauthorizedHandler(cb: (() => void) | null): void {
  onUnauthorized = cb;
}

/**
 * Transient-401 tolerance (#487). The workstation verifies POS bearer tokens
 * by asking Cloud, with a short-lived cache. Right after a workstation restart
 * (or a network blip) that cache is cold and can reject a still-valid token
 * for 1-2s — a lone, spurious 401. Logging out on the first 401 wiped the
 * session and kicked the terminal to /pairing mid-shift. Instead we only clear
 * after AUTH_FAIL_THRESHOLD *consecutive* 401s; any success resets the streak,
 * so a genuinely revoked token (401 on every call) still logs out in well
 * under a second while an isolated blip is absorbed.
 */
const AUTH_FAIL_THRESHOLD = 3;
let consecutiveAuthFailures = 0;

/** Test/HMR seam — reset the streak between cases. */
export function resetAuthFailureStreak(): void {
  consecutiveAuthFailures = 0;
}

function endSession(): void {
  consecutiveAuthFailures = 0;
  // Clear token sync so concurrent requests don't fire with a stale token,
  // then notify the registered handler (AuthProvider) to redirect via router.
  clearSession();
  onUnauthorized?.();
}

/**
 * Handler for soft auth failures that should NOT wipe the token (e.g. Cloud
 * briefly unreachable → 503 "auth verification unavailable"). AuthProvider
 * shows a "reconnect / re-pair" banner so the cashier is never stuck staring
 * at a dead POS with no way out.
 */
let onAuthRecovery: ((reason: string) => void) | null = null;

export function setAuthRecoveryHandler(
  cb: ((reason: string) => void) | null,
): void {
  onAuthRecovery = cb;
}

function messageOf(body: Record<string, unknown>): string {
  return typeof body.message === "string" ? body.message : "";
}

/**
 * NEVER decide a session's fate by sniffing prose.
 *
 * There used to be a `isDefinitiveInvalidToken` fast path here matching
 * `message.includes("invalid token")`. That is the exact string the
 * workstation emits for ANY Cloud rejection: `CloudVerifier.Verify` collapses
 * both 401 and 403 into `ErrUnauthorized`, and `AuthMiddleware.Wrap` answers
 * `writeError(w, 401, "invalid token")`. So one transient 403 in front of
 * `/api/v1/devices/me` — a WAF challenge, a deploy blip, a proxy hiccup —
 * wiped a perfectly valid pairing mid-shift and left the till unable to sell
 * until an admin minted a fresh 6-digit code. That is precisely the failure
 * #487's streak exists to absorb, defeated by a "fast path" bolted on top.
 *
 * A definitive kill needs a machine-readable signal the server actually sends.
 * Today only 403 BRANCH_MISMATCH qualifies (Cloud: ResolvesShopContext;
 * workstation: writeBranchMismatch). Everything else — including every 401 —
 * goes through the streak.
 */
function isBranchMismatch(status: number, body: Record<string, unknown>): boolean {
  return status === 403 && body.code === "BRANCH_MISMATCH";
}

function isAuthVerificationUnavailable(
  status: number,
  body: Record<string, unknown>,
): boolean {
  if (status !== 503) return false;
  return messageOf(body).toLowerCase().includes("auth verification unavailable");
}

/**
 * Decide what an error response means for the session.
 *  - 403 with code BRANCH_MISMATCH: the device is paired to a different branch
 *    than this workstation serves. A hard config error, never transient →
 *    clear now so the terminal re-pairs (#472).
 *  - ANY 401 with a token we actually sent: count toward the streak; clear
 *    only once it crosses the threshold (#487 transient cold-cache blips).
 *    No message-based shortcut — see isBranchMismatch above for why.
 *  - 503 "auth verification unavailable": Cloud/WS verify path is broken —
 *    keep the token (it may still be valid) but surface a reconnect banner.
 *  - 401 with no token: leave the session alone (#472 token-race).
 */
function handleAuthFailure(
  status: number,
  body: Record<string, unknown>,
  hadToken: boolean,
): void {
  if (isBranchMismatch(status, body)) {
    endSession();
    return;
  }
  if (!hadToken) return;
  if (isAuthVerificationUnavailable(status, body)) {
    // The guard above already matched the phrase inside body.message, so the
    // message is non-empty by construction — no `||` fallback to hide behind.
    onAuthRecovery?.(messageOf(body));
    return;
  }
  if (status !== 401) return;
  consecutiveAuthFailures += 1;
  if (consecutiveAuthFailures >= AUTH_FAIL_THRESHOLD) {
    endSession();
  }
}

/**
 * Read the paired-device token. Delegates to auth.ts, the single owner of the
 * storage-key contract (`pos_device_token`). This module previously read the
 * wrong key ("token") plus a legacy cookie, so paired devices sent no
 * Authorization header and every /pos/* call 401'd (#420).
 */
function getToken(): string | null {
  return getStoredToken();
}

/** POS endpoints require a paired device token; other namespaces may not. */
function isPosPath(path: string): boolean {
  return path.includes("/pos/");
}

function getLocale(): string {
  if (typeof document === "undefined") return "ja";
  // Same key AppProvider persists (LOCALE_STORAGE_KEY = "pos_locale"). The old
  // "app_locale" key never existed, so Accept-Language ignored the operator's
  // language selection.
  return (
    localStorage.getItem(LOCALE_STORAGE_KEY) ||
    import.meta.env.VITE_DEFAULT_LOCALE ||
    "ja"
  );
}

const WORKSTATION_TIMEOUT_MS = 3_000; // fail fast on LAN
const CLOUD_TIMEOUT_MS = 15_000; // existing default

/**
 * True for transport failures. Read requests may use this signal to try Cloud;
 * writes use it to raise AmbiguousMutationError and must not double-call.
 *
 * Exported for #1501: the light-action queue must tell "not sent yet" (keep,
 * retry later) from "the server said no" (drop) using the SAME rule apiFetch
 * uses. Two copies of this predicate would drift, and the drift shows up as a
 * queue that never drains.
 */
export function isNetworkError(err: unknown): boolean {
  if (err instanceof DOMException && err.name === "AbortError") return true;
  if (err instanceof TypeError) return true; // fetch threw before getting a response
  // #2951 wraps a write's transport failure into AmbiguousMutationError before
  // rethrowing. The underlying condition is unchanged — no authoritative HTTP
  // response came back — so this predicate must still say "true", or the wrap
  // silently reclassifies "never sent" as "the server said no".
  //
  // Đo được khi gộp nhánh: thiếu dòng này thì đổi trạng thái bàn lúc mất mạng
  // KHÔNG được xếp hàng (`use-tables.ts` chỉ xếp khi `isNetworkError`), và
  // hành động đã nằm trong hàng đợi bị `replayLightActions` XOÁ ở lượt phát
  // lại đầu tiên — đúng hai vế mà #1501 sinh ra để chống.
  //
  // Giữ ĐÚNG MỘT predicate là có chủ đích, và docblock trên đã nói vì sao. Việc
  // "có được phát lại không" thuộc về NGƯỜI GỌI: hàng đợi nhẹ chỉ chứa hành
  // động idempotent (`table.status` — ghi sau đè ghi trước), còn đường tiền
  // không đi qua hàng đợi này và vẫn nhận nguyên `AmbiguousMutationError` để
  // dừng lại đối soát. Thêm một hành động KHÔNG idempotent vào hàng đợi thì
  // phải xét lại chỗ này trước.
  if (err instanceof AmbiguousMutationError) return true;
  return false;
}

function requestMethod(options: RequestInit | undefined): string {
  return (options?.method ?? "GET").toUpperCase();
}

function isMutationRequest(options: RequestInit | undefined): boolean {
  const method = requestMethod(options);
  return method !== "GET" && method !== "HEAD" && method !== "OPTIONS";
}

async function doFetch<T>(
  baseUrl: string,
  path: string,
  token: string | null,
  locale: string,
  options: RequestInit | undefined,
  timeoutMs: number,
): Promise<T> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    const shopSlug = getCurrentShopSlug();
    const response = await fetch(`${baseUrl}${path}`, {
      ...options,
      signal: controller.signal,
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "Accept-Language": locale,
        ...(token && { Authorization: `Bearer ${token}` }),
        // Backend ResolvePosShop reads this header for /api/v1/pos/*; harmless
        // when calling other namespaces (Laravel will ignore unknown headers).
        ...(shopSlug && { "X-Shop-Slug": shopSlug }),
        // Heartbeat: AuthenticateDevice/AuthenticateSsoOrDevice refresh
        // devices.app_version from this header. Without it the admin devices
        // page shows the pair-time number forever (app_version_source stays
        // "pairing"). Baked in by vite.config's define — same value the
        // pos-bundle-version identity carries.
        ...(import.meta.env.VITE_APP_VERSION && {
          "X-App-Version": import.meta.env.VITE_APP_VERSION,
        }),
        ...options?.headers,
      },
    });

    if (!response.ok) {
      const body = await response.json().catch(() => ({}));
      handleAuthFailure(response.status, body, token != null);
      throw new ApiError(response.status, body);
    }

    // Any authoritative success clears the transient-401 streak (#487).
    consecutiveAuthFailures = 0;

    if (response.status === 204) return null as T;
    return response.json();
  } finally {
    clearTimeout(timer);
  }
}

export interface ApiFetchOptions extends RequestInit {
  /**
   * Bypass the LAN/Cloud resolver and always hit CLOUD_URL. Use for
   * endpoints the workstation does NOT mirror (e.g. plan-030 /pos/till/*
   * — workstation mirror is a follow-up per DESIGN.md Risks). Without
   * this flag, workstation returns 404 and the resolver — which only
   * retries on NETWORK errors, not 4xx — would surface the 404 to the
   * caller.
   */
  forceCloud?: boolean;
}

export async function apiFetch<T>(
  path: string,
  options?: ApiFetchOptions,
): Promise<T> {
  const token = getToken();

  // Fail-fast: never fire a /pos/* request without a token. The data hooks
  // gate on shopSlug (not token), so without this guard an unpaired terminal
  // spams /pos/* and drowns the console in 401s (#472). Throw locally so the
  // caller still gets a clean error — but don't touch the session: there's
  // nothing to clear and no server actually rejected us.
  if (!token && isPosPath(path)) {
    throw new ApiError(401, { message: "not paired", code: "NOT_PAIRED" });
  }

  const locale = getLocale();

  const { forceCloud, ...fetchOptions } = options ?? {};
  const target = forceCloud
    ? { url: CLOUD_URL, via: "cloud" as const }
    : resolveBaseUrl();
  const usingWorkstation = target.via === "workstation";
  const timeout = usingWorkstation ? WORKSTATION_TIMEOUT_MS : CLOUD_TIMEOUT_MS;
  const mutation = isMutationRequest(fetchOptions);

  try {
    const result = await doFetch<T>(
      target.url,
      path,
      token,
      locale,
      fetchOptions,
      timeout,
    );
    recordRequest(target.via, "ok");
    // Tín hiệu ĐÓNG MẠCH (#2689). Không có nó thì `consecutiveFailures` chỉ
    // tăng, và ba cái chớp rải rác suốt một ca cộng dồn thành một lần ngắt
    // mạch dù workstation chưa bao giờ thật sự hỏng.
    if (usingWorkstation) markWorkstationReachable();
    markApiOutcome("reached-server");
    return result;
  } catch (err) {
    // Auto-mode safety net: workstation network error → try Cloud once.
    // Only in auto mode — explicit "workstation" mode means user wants to see
    // the error (e.g. troubleshooting). 4xx/5xx responses are NOT retried —
    // they're authoritative server replies.
    if (usingWorkstation && getMode() === "auto" && isNetworkError(err)) {
      // No response bytes came back. A read can safely fail over; a write may
      // already have committed and must stop here for reconciliation.
      recordRequest("workstation", "fail");
      markWorkstationUnreachable();
      if (mutation) {
        markApiOutcome("network-error");
        throw new AmbiguousMutationError(
          requestMethod(fetchOptions),
          path,
          locale,
          err,
        );
      }
      try {
        const result = await doFetch<T>(
          CLOUD_URL,
          path,
          token,
          locale,
          fetchOptions,
          CLOUD_TIMEOUT_MS,
        );
        recordRequest("cloud", "ok");
        markApiOutcome("reached-server");
        return result;
      } catch (cloudErr) {
        recordRequest("cloud", "fail");
        // LAN đã hỏng mạng và Cloud cũng hỏng mạng ⇒ đây là lời gọi thật sự
        // không với tới đâu cả. Nhưng nếu Cloud trả 4xx/5xx thì có mạng —
        // một lỗi nghiệp vụ không được hiển thị thành "mất kết nối" (#1501).
        markApiOutcome(
          isNetworkError(cloudErr) ? "network-error" : "reached-server",
        );
        throw cloudErr;
      }
    }
    // Any other error (4xx/5xx authoritative, explicit mode without retry):
    // attribute the failure to whichever target we hit.
    recordRequest(target.via, "fail");
    markApiOutcome(isNetworkError(err) ? "network-error" : "reached-server");
    if (mutation && isNetworkError(err)) {
      throw new AmbiguousMutationError(
        requestMethod(fetchOptions),
        path,
        locale,
        err,
      );
    }
    throw err;
  }
}

/**
 * Standard godx-tempo JSON envelope.
 * See MEMORY.md → project_api_response_envelope.
 */
export interface ApiEnvelope<T> {
  statusCode: number;
  message: string;
  data: T;
  timestamp: string;
  path: string;
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
