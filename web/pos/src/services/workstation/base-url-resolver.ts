/**
 * Resolves the base URL for the next API call based on:
 *   - User's preferred API mode (Settings → Auto/Workstation/Cloud)
 *   - Workstation reachability (auto mode falls back to Cloud after timeout)
 *
 * Cloud is always reachable as a fallback. Workstation is LAN-only and may
 * go down during a shift — auto mode handles that gracefully with a short
 * backoff window so we don't hammer a dead workstation on every call.
 */

// When served BY the workstation (base "/pos/"), the Cloud-mode backend URL
// comes from the workstation at RUNTIME: it injects `<meta name="x-pos-cloud-url"
// content="…">` into the served index.html from its WS_APP_CLOUD_URL env. So a
// SINGLE .env on the workstation (mini-PC) drives BOTH pairing (via the
// workstation relay) and Cloud mode — no pos-web rebuild to point at a different
// backend. Absent (Amplify / dev, or an older workstation) → fall back to the
// build-time VITE_API_URL. Read once at module load; the meta is in <head>
// before this module's script, so it is already parsed.
function runtimeCloudUrl(): string {
  if (import.meta.env.BASE_URL !== "/pos/" || typeof document === "undefined") {
    return "";
  }
  const injected = document
    .querySelector('meta[name="x-pos-cloud-url"]')
    ?.getAttribute("content")
    ?.trim();
  return injected ?? "";
}

const configuredCloudUrl =
  runtimeCloudUrl() || import.meta.env.VITE_API_URL || "http://localhost:5400";

// Amplify production sets VITE_API_URL=/ so Cloud calls stay on the current
// origin and are forwarded by the versioned /api reverse-proxy rule. Normalise
// that sentinel to an empty base; otherwise `${CLOUD_URL}/api/...` would become
// the protocol-relative URL `//api/...`.
//
// Cloud mode ALWAYS targets the backend directly (dev: http://localhost:5400;
// prod: the deployed cloud host from the workstation's .env), never the
// workstation — that is the whole point of the mode toggle: LAN mode → the
// workstation (getWorkstationUrl), Cloud mode → the backend (here). A
// workstation-served page hitting the backend in Cloud mode is cross-origin, so
// the backend must allow that origin (CORS) — a backend concern, not a reason to
// reroute Cloud traffic through the workstation.
export const CLOUD_URL =
  configuredCloudUrl === "/"
    ? ""
    : configuredCloudUrl.replace(/\/+$/, "");

/** Build-time seed. Each shop's workstation runs its own port (operator-set,
 * to dodge conflicts with other LAN software), so a single pos-web bundle
 * deployed to many shops can't bake one URL in for everyone — this constant
 * is only the fallback until the operator pairs a real one (see below). */
const BUILD_TIME_WORKSTATION_URL =
  import.meta.env.VITE_WORKSTATION_API_URL || "http://localhost:8080";

const STORAGE_WORKSTATION_URL = "pos_workstation_url";

/**
 * plan-052 T3.5 (#1169) — the workstation build serves this bundle FROM the
 * workstation, mounted at `/pos/`. In that deployment the workstation is not
 * something to pair with: it is the origin the page was loaded from.
 *
 * Derived from `import.meta.env.BASE_URL` rather than a separate flag on
 * purpose — `BASE_URL` IS the vite `base` the bundle was built with, so the
 * mode cannot drift from where the bundle is actually mounted. One codebase,
 * two builds, and the difference lives here in the resolver instead of an
 * if-forest through the business logic (owner ruling 2026-07-28).
 *
 * Why it matters: a tablet loading pos-web from Amplify over HTTPS cannot call
 * `http://<lan-ip>` — the browser blocks mixed content, so printing and every
 * other LAN feature is dead in a multi-device shop. Served from the
 * workstation over plain http, every call is same-origin and the problem does
 * not exist.
 */
const SERVED_BY_WORKSTATION = import.meta.env.BASE_URL === "/pos/";

/** True when this bundle is being served BY a workstation (base `/pos/`). */
export function isServedByWorkstation(): boolean {
  return SERVED_BY_WORKSTATION;
}

/**
 * Per-device workstation URL (IP:port), set from Settings → Connection when
 * the operator pairs this terminal to its shop's workstation. Falls back to
 * the build-time env var so existing single-shop deploys keep working
 * unchanged. Stored in localStorage — NOT baked into the bundle — because the
 * port varies per shop/device and must be changeable without a rebuild.
 */
export function getWorkstationUrl(): string {
  // Same-origin build: the workstation is whatever origin served this page, and
  // a stale paired IP left in localStorage must NOT win over it — that stored
  // value is how a tablet ends up calling a machine it can no longer reach.
  if (SERVED_BY_WORKSTATION && typeof location !== "undefined") {
    return location.origin;
  }
  if (typeof localStorage === "undefined") return BUILD_TIME_WORKSTATION_URL;
  const stored = localStorage.getItem(STORAGE_WORKSTATION_URL);
  return stored && stored.trim() !== "" ? stored : BUILD_TIME_WORKSTATION_URL;
}

/** Persist an operator-entered workstation URL (e.g. "http://192.168.1.50:6969"). */
export function setWorkstationUrl(url: string): void {
  if (typeof localStorage === "undefined") return;
  const trimmed = url.trim().replace(/\/+$/, "");
  if (trimmed === "") {
    localStorage.removeItem(STORAGE_WORKSTATION_URL);
  } else {
    localStorage.setItem(STORAGE_WORKSTATION_URL, trimmed);
  }
  unreachableUntil = 0; // give the newly-paired address a fresh try
}

/** Clear the stored override, reverting to the build-time default. */
export function clearWorkstationUrl(): void {
  if (typeof localStorage !== "undefined") {
    localStorage.removeItem(STORAGE_WORKSTATION_URL);
  }
  unreachableUntil = 0;
}

/**
 * True when a real workstation is configured. Deploys without a LAN
 * workstation set `VITE_WORKSTATION_API_URL=none` (a sentinel, not a URL);
 * an empty value means the same. Callers that talk straight to the
 * workstation (print service, WS socket) must gate on this so they don't
 * treat "none" as a host and hammer a bogus URL — see #422.
 */
export function hasWorkstation(): boolean {
  const v = getWorkstationUrl().trim().toLowerCase();
  return v !== "" && v !== "none";
}

export type ApiMode = "auto" | "workstation" | "cloud";

const STORAGE_MODE = "pos_api_mode";
const UNREACHABLE_BACKOFF_MS = 30_000;

/**
 * Hard override (#472). Pins the resolver to one target regardless of the
 * stored preference. Leave it unset in dev/CI to keep the interactive
 * Settings → Connection toggle working.
 *
 * The original reason for this override is DEAD (#2687): it read "Cloud does
 * NOT serve /pos/* for device tokens (it replies 401)", so a single workstation
 * blip flipping auto mode to Cloud meant a wall of 401s. Cloud has since served
 * /pos/* to device tokens — `backend/routes/api.php` mounts the group behind
 * `auth.sso_or_device`, and `AuthenticateSsoOrDevice` exists precisely so
 * pos-web can fall back LAN → cloud with the device token it already holds.
 * Proven by `AuthenticateSsoOrDeviceTest` + `ResolvePosShopTest`.
 *
 * So the override survives as a deployment escape hatch — pinning a shop that
 * must not drift between targets — NOT as a guard against an auth wall. Do not
 * reach for it to "avoid 401s"; that failure mode no longer exists.
 */
const FORCED_MODE: ApiMode | null = ((v): ApiMode | null =>
  v === "auto" || v === "workstation" || v === "cloud" ? v : null)(
  import.meta.env.VITE_POS_API_MODE,
);

/** True when VITE_POS_API_MODE pins the mode (Settings toggle is inert). */
export function isModeForced(): boolean {
  return FORCED_MODE !== null;
}

/**
 * Số lỗi LIÊN TIẾP phải thấy trước khi ngắt mạch (#2689).
 *
 * Bản trước ngắt sau ĐÚNG MỘT lỗi mạng. Một cái chớp LAN — tablet đổi AP, một
 * gói rơi — là đủ để terminal bỏ workstation suốt 30 giây và chạy qua Cloud,
 * tức bỏ đúng đường ít trễ hơn VÀ đường duy nhất còn sống khi mất Internet.
 *
 * Ba lỗi liên tiếp là ngưỡng chuẩn của mẫu circuit breaker: đủ bằng chứng để
 * kết luận phụ thuộc đã hỏng, thay vì phản ứng với một mẫu duy nhất.
 */
const FAILURE_THRESHOLD = 3;

/** Mutable runtime state. Single-process JS so no need for locks. */
let unreachableUntil = 0;
let consecutiveFailures = 0;

/**
 * Mode a terminal lands on before anyone touches Settings → Connection.
 *
 * Amplify build (base `/`): `cloud`. LAN access is opt-in there — the page came
 * from the internet, the workstation is a separate machine that has to be paired
 * by IP first, and `cloud` IS the same-origin answer.
 *
 * Workstation build (base `/pos/`): `workstation`. The same reasoning inverts —
 * the page was served BY the workstation, so the LAN gateway is same-origin and
 * reachable by construction, while Cloud is the cross-origin one. Defaulting to
 * `cloud` here sent every /api/v1/pos/* call to the backend and 404'd the
 * endpoints only the workstation has (`/pos/terminal/*` for the P400, the
 * plan-044 till reads) — and it broke #1169's whole point: "mất Internet vẫn mở
 * app, vẫn bán, vẫn in".
 *
 * Not `auto`: that mode falls back to Cloud on a network error (api.ts), which
 * on this build is a fallback to the far side of an internet link the shop may
 * not have. If the workstation is down, the page could not have loaded at all —
 * there is no service worker on this build (pwa-options.ts). The escape hatch
 * for a workstation that dies mid-shift is the shift-gate's "switch side"
 * button, which flips to Cloud on demand.
 *
 * `.env.workstation` has described this behaviour to operators since the
 * embedded build shipped ("The embedded build defaults to WORKSTATION mode
 * (base-url-resolver.ts defaultMode)"); the code never implemented it.
 */
function defaultMode(): ApiMode {
  return SERVED_BY_WORKSTATION ? "workstation" : "cloud";
}

export function getMode(): ApiMode {
  if (FORCED_MODE) return FORCED_MODE;
  if (typeof localStorage === "undefined") return defaultMode();
  const stored = localStorage.getItem(STORAGE_MODE);
  if (stored === "auto" || stored === "workstation" || stored === "cloud") {
    return stored;
  }
  return defaultMode();
}

export function setMode(mode: ApiMode): void {
  if (typeof localStorage !== "undefined") {
    localStorage.setItem(STORAGE_MODE, mode);
  }
  // Reset backoff: when user manually changes mode, give workstation a fresh try.
  unreachableUntil = 0;
}

export interface ResolvedTarget {
  url: string;
  via: "workstation" | "cloud";
}

/**
 * Resolve the base URL for the NEXT api call. Pure function — doesn't mutate.
 * In auto mode, respects the unreachable backoff window so we don't retry
 * workstation immediately after a network failure.
 */
export function resolveBaseUrl(): ResolvedTarget {
  const mode = getMode();
  // No workstation configured (`VITE_WORKSTATION_API_URL=none` / empty) →
  // Cloud is the only reachable target regardless of the stored mode. This
  // stops the first auto-mode call from hitting the "none" sentinel host
  // before the unreachable-backoff would otherwise kick in (#422).
  if (!hasWorkstation()) return { url: CLOUD_URL, via: "cloud" };
  if (mode === "cloud") return { url: CLOUD_URL, via: "cloud" };
  if (mode === "workstation")
    return { url: getWorkstationUrl(), via: "workstation" };

  // auto: chỉ né workstation khi mạch ĐANG ngắt. Ở half-open thì đi workstation
  // — chính lượt gọi đó LÀ phép dò, không cần một request thăm dò riêng (một
  // request riêng đo được sức khoẻ của endpoint thăm dò, không phải của đường
  // mà đơn hàng thật sự đi qua).
  if (breakerState() === "open") return { url: CLOUD_URL, via: "cloud" };
  return { url: getWorkstationUrl(), via: "workstation" };
}

/**
 * Trạng thái mạch, suy ra từ hai biến chứ không giữ riêng một enum — hai nguồn
 * sự thật cho cùng một trạng thái là chỗ chúng trôi khỏi nhau.
 *
 *   closed     — chưa đủ lỗi liên tiếp; đi workstation.
 *   open       — đã ngắt, còn trong backoff; đi Cloud.
 *   half-open  — hết backoff, CHƯA biết workstation đã sống lại chưa. Cho đúng
 *                một lượt gọi thật đi qua workstation làm phép dò; kết quả của
 *                nó đóng mạch hoặc ngắt lại.
 */
export type BreakerState = "closed" | "open" | "half-open";

export function breakerState(): BreakerState {
  if (unreachableUntil === 0) return "closed";
  return Date.now() < unreachableUntil ? "open" : "half-open";
}

/**
 * Một lượt gọi workstation thất bại vì lỗi MẠNG (auto-mode).
 *
 * Ở half-open thì phép dò vừa hỏng ⇒ ngắt lại NGAY, không bắt đếm lại từ đầu:
 * ta vừa có bằng chứng tươi rằng nó còn hỏng.
 */
export function markWorkstationUnreachable(): void {
  if (breakerState() === "half-open") {
    unreachableUntil = Date.now() + UNREACHABLE_BACKOFF_MS;
    return;
  }

  consecutiveFailures += 1;
  if (consecutiveFailures >= FAILURE_THRESHOLD) {
    unreachableUntil = Date.now() + UNREACHABLE_BACKOFF_MS;
  }
}

/**
 * Một lượt gọi workstation THÀNH CÔNG — đóng mạch và xoá bộ đếm.
 *
 * Đây là nửa còn thiếu của mẫu: không có tín hiệu thành công thì bộ đếm chỉ
 * tăng, và ba cái chớp rải rác suốt một ca sẽ cộng dồn thành một lần ngắt mạch
 * dù workstation chưa bao giờ thật sự hỏng. "Liên tiếp" phải có thứ phá chuỗi.
 */
export function markWorkstationReachable(): void {
  consecutiveFailures = 0;
  unreachableUntil = 0;
}

/**
 * Clear the backoff — call when network state changes (online event) or when
 * the user manually requests a connection test from Settings.
 */
export function resetUnreachable(): void {
  unreachableUntil = 0;
  consecutiveFailures = 0;
}

/** True when the current call would route through workstation. */
export function isUsingWorkstation(): boolean {
  return resolveBaseUrl().via === "workstation";
}

/** Inspection helper for UI. ms remaining until next retry; 0 if not in backoff. */
export function unreachableTimeRemaining(): number {
  const remaining = unreachableUntil - Date.now();
  return remaining > 0 ? remaining : 0;
}
