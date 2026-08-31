import * as Application from "expo-application";
import * as Device from "expo-device";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { Platform } from "react-native";
import type { ItemAllocation, Order, SplitByItemsPreview } from "../types/kiosk";
import type { EffectivePaymentOptionsSnapshot } from "../types/effective-payment-options";
import type { KioskPrinter } from "../types/printer";

import {
  getDeviceToken,
  setDeviceToken as setDeviceTokenRaw,
  clearDeviceToken,
} from "./device-token";
import { normalizeOrderImages } from "./media";
import {
  CLOUD_URL,
  markCloudUnreachable,
  resolveBaseUrl,
  resolveWorkstationUrl,
} from "../services/workstation/base-url-resolver";
import { devLog } from './dev-log';

export { getDeviceToken, clearDeviceToken };

export async function setDeviceToken(token: string): Promise<void> {
  await setDeviceTokenRaw(token);
  resetUnauthorizedGuard();
}

const STORAGE_LOCALE = "tms_locale";
const DEFAULT_LOCALE = "ja";

// Pairing always hits Cloud directly (workstation has no pairing endpoint).
const API_BASE = CLOUD_URL;

/** Paginated API response shape (mirrors Laravel's LengthAwarePaginator). */
export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: Record<string, unknown>,
  ) {
    super((body.message as string) || `API Error ${status}`);
  }

  /** True if the device token is missing/invalid/revoked (401). */
  get isAuthError(): boolean {
    return this.status === 401;
  }

  /** True if the device is authenticated but lacks scope for this endpoint (403). */
  get isForbidden(): boolean {
    return this.status === 403;
  }

  /** True if the error is a validation failure (Laravel 422). */
  get isValidationError(): boolean {
    return this.status === 422;
  }

  /** True if the error is a server error (5xx). */
  get isServerError(): boolean {
    return this.status >= 500;
  }
}

/**
 * #935 — thrown by the pair success-path guard when an OLD backend (without
 * the expected_type gate) hands back a non-kiosk device. The token is never
 * stored; the login screen shows a wrong-device-type message.
 */
export class DeviceTypeMismatchError extends Error {
  constructor(public actualType: string) {
    super(`Pairing code belongs to a "${actualType}" device, not a kiosk`);
  }
}

/**
 * Handler registry for 401 (unauthorized) responses. AuthProvider registers
 * a callback on mount that clears auth state and redirects to /login.
 *
 * Kept as module-level state because apiFetch is a plain async function with
 * no React context — using a callback registry avoids coupling api.ts to
 * AuthProvider while still allowing global response to token expiry.
 */
let onUnauthorized: (() => void) | null = null;

export function setUnauthorizedHandler(cb: (() => void) | null): void {
  onUnauthorized = cb;
}

/**
 * Module-level guard so clearDeviceToken / onUnauthorized fire at most once
 * per token. Reset by setDeviceToken (next pairing). Without this guard, a
 * burst of 401s from polling + audit-log + confirm racing against an
 * expired token would each await clearDeviceToken (a SecureStore I/O write)
 * and re-fire the unauthorized handler. AuthProvider's flag-flip is
 * idempotent but the I/O storm + log noise is not.
 */
let unauthorizedTriggered = false;

function resetUnauthorizedGuard(): void {
  unauthorizedTriggered = false;
}

/** Timeout error for requests that take too long. */
export class TimeoutError extends Error {
  constructor(public url: string, public timeoutMs: number) {
    super(`Request to ${url} timed out after ${timeoutMs}ms`);
  }
}

/**
 * Thrown when a LAN-only call has no workstation to talk to.
 *
 * Printing is the only such call: Cloud has no print endpoint, so routing a
 * print there yields a confusing 404 instead of "there is no workstation on
 * this network". Callers surface this to the operator — it must never fail
 * silently (issue #44 Phase B).
 */
export class WorkstationUnavailableError extends Error {
  constructor(public path: string) {
    super(`No workstation reachable on the LAN for ${path}`);
    this.name = "WorkstationUnavailableError";
  }
}

const DEFAULT_TIMEOUT = 15_000; // 15s for Cloud
const WORKSTATION_TIMEOUT = 3_000; // 3s for LAN — fail fast to fallback

/**
 * True for fetch errors that warrant trying Cloud as fallback (workstation
 * is down, wrong subnet, etc). Pure network failures, not HTTP 4xx/5xx.
 */
function isNetworkError(e: unknown): boolean {
  if (e instanceof TimeoutError) return true;
  if (e instanceof TypeError) return true; // fetch threw before getting a response
  return false;
}

async function doFetch<T>(
  baseUrl: string,
  path: string,
  token: string | null,
  locale: string | null,
  options: (RequestInit & { timeout?: number; suppressUnauthorizedHandler?: boolean }) | undefined,
  timeoutMs: number,
): Promise<T> {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetch(`${baseUrl}${path}`, {
      ...options,
      signal: controller.signal,
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "Accept-Language": locale ?? DEFAULT_LOCALE,
        ...(token && { Authorization: `Bearer ${token}` }),
        ...options?.headers,
      },
    });

    if (!response.ok) {
      const body = await response.json().catch(() => ({}));
      // #935 — a 403 DEVICE_TYPE_NOT_ALLOWED means this token belongs to a
      // device type the kiosk surface will never accept (paired via an old
      // backend without the expected_type gate). No retry can fix it — treat
      // it like a dead token: clear + kick back to pairing instead of letting
      // every screen render empty.
      const wrongDeviceType =
        response.status === 403 &&
        (body as { code?: string }).code === "DEVICE_TYPE_NOT_ALLOWED";
      if (
        (response.status === 401 || wrongDeviceType) &&
        !options?.suppressUnauthorizedHandler
      ) {
        // Token bad — clear it now and notify the registered handler.
        // Same path for workstation and Cloud responses: workstation forwards
        // verify to Cloud anyway, so a 401 from either is authoritative.
        // Guard: a burst of concurrent 401s (polling + confirm + audit-log)
        // must collapse to one clear + one handler fire. Guard resets when
        // setDeviceToken runs (re-pairing).
        if (!unauthorizedTriggered) {
          unauthorizedTriggered = true;
          await clearDeviceToken();
          onUnauthorized?.();
        }
      }
      throw new ApiError(response.status, body);
    }

    if (response.status === 204) return null as T;
    return response.json();
  } catch (e) {
    if (e instanceof Error && e.name === "AbortError") {
      throw new TimeoutError(`${baseUrl}${path}`, timeoutMs);
    }
    throw e;
  } finally {
    clearTimeout(timer);
  }
}

export interface ApiFetchOptions extends RequestInit {
  timeout?: number;
  suppressUnauthorizedHandler?: boolean;
  /** Pin to Cloud, skipping LAN routing entirely. */
  forceCloud?: boolean;
  /** Pin to the LAN workstation. Throws WorkstationUnavailableError if none. */
  workstationOnly?: boolean;
}

export async function apiFetch<T>(path: string, options?: ApiFetchOptions): Promise<T> {
  const [token, locale] = await Promise.all([
    getDeviceToken(),
    AsyncStorage.getItem(STORAGE_LOCALE),
  ]);

  // LAN-only endpoints (printing). Cloud has no equivalent, so there is
  // nothing to fall back to — a missing workstation is a hard, visible error.
  if (options?.workstationOnly) {
    const workstationUrl = resolveWorkstationUrl();
    if (!workstationUrl) throw new WorkstationUnavailableError(path);
    const result = await doFetch<T>(
      workstationUrl,
      path,
      token,
      locale,
      options,
      options.timeout ?? WORKSTATION_TIMEOUT,
    );
    return result;
  }

  // `forceCloud` pins the request to Cloud, skipping LAN/workstation routing.
  // Use it for public Cloud-owned endpoints the workstation doesn't proxy
  // (e.g. customer QR resolve) — otherwise the workstation answers with its
  // SPA index.html (200 + HTML), which then explodes in `response.json()`.
  const baseUrl = options?.forceCloud ? CLOUD_URL : resolveBaseUrl();
  const usingWorkstation = baseUrl !== CLOUD_URL;
  const timeout =
    options?.timeout ?? (usingWorkstation ? WORKSTATION_TIMEOUT : DEFAULT_TIMEOUT);

  try {
    return await doFetch<T>(baseUrl, path, token, locale, options, timeout);
  } catch (e) {
    // ApiError (4xx/5xx) is an authoritative response from whichever leg
    // answered — don't re-issue the call against the other one.
    if (!isNetworkError(e)) throw e;

    if (usingWorkstation) {
      // The LAN standby failed on this request too — give Cloud one more shot
      // (it may have just recovered). No workstation backoff is recorded: we
      // only reach the workstation while Cloud is known-down, and skipping the
      // workstation then would just starve the lifeline. See resolver finding #2.
      const result = await doFetch<T>(
        CLOUD_URL,
        path,
        token,
        locale,
        options,
        options?.timeout ?? DEFAULT_TIMEOUT,
      );
      return result;
    }

    // Cloud failed. Hand over to the workstation when one is known — this is
    // the whole point of keeping the LAN leg alive (issue #44): cloud outage
    // must not stop the restaurant from taking money.
    if (options?.forceCloud) throw e;
    const workstationUrl = resolveWorkstationUrl();
    if (!workstationUrl) throw e;
    markCloudUnreachable();
    const result = await doFetch<T>(
      workstationUrl,
      path,
      token,
      locale,
      options,
      options?.timeout ?? WORKSTATION_TIMEOUT,
    );
    return result;
  }
}

/**
 * Collect device hardware/OS info for pairing payload.
 */
export async function getDeviceInfo(): Promise<Record<string, string | null>> {
  const iosId = Platform.OS === "ios" ? await Application.getIosIdForVendorAsync() : null;
  const androidId = Platform.OS === "android" ? Application.getAndroidId() : null;

  return {
    device_uuid: iosId ?? androidId ?? null,
    os: `${Platform.OS} ${Platform.Version}`,
    model: Device.modelName,
    brand: Device.brand,
    device_name: Device.deviceName,
    app_version: Application.nativeApplicationVersion,
    build_version: Application.nativeBuildVersion,
  };
}

/**
 * Pair device with pairing code. Sends device UUID + hardware info.
 */
export async function pairDevice(
  pairingCode: string,
): Promise<{ device_token: string; device: Record<string, unknown> }> {
  const deviceInfo = await getDeviceInfo();
  const response = await fetch(`${API_BASE}/api/v1/devices/pair`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
    },
    body: JSON.stringify({
      pairing_code: pairingCode.toUpperCase().trim(),
      // #935 — mirror the device.auth:kiosk allow-set: the backend rejects a
      // code belonging to any other device type with a clear 422 BEFORE
      // mutating, so the code stays usable by the right app.
      expected_type: "kiosk",
      device_info: deviceInfo,
    }),
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    throw new ApiError(response.status, body);
  }

  return response.json();
}

/**
 * Resolve an order by its code (ORD-YYYY-XXXX) and return the full order.
 */
export async function fetchOrderByCode(
  orderCode: string,
): Promise<Order> {
  // Default routing (workstation-first, Cloud fallback) — same as dine-in
  // by-table. The WS must serve the normalized single-order shape (see WS
  // parity plan W1); until then it returns a raw CRUD list and the bill breaks.
  const path = `/api/v1/kiosk/orders?code=${encodeURIComponent(orderCode)}`;
  devLog(`[order-fetch] GET ${resolveBaseUrl()}${path}`);
  try {
    const res = await apiFetch<{ data: Order }>(path);
    devLog(
      `[order-fetch] ok — keys=[${res.data ? Object.keys(res.data).join(",") : "null"}] items=${
        Array.isArray(res.data?.items) ? `array(${res.data.items.length})` : typeof res.data?.items
      }`,
    );
    devLog(`[order-fetch] raw data →`, JSON.stringify(res.data));
    return normalizeOrderImages(res.data);
  } catch (e) {
    const status = e instanceof ApiError ? e.status : "network";
    console.warn(`[order-fetch] FAILED status=${status} —`, e);
    throw e;
  }
}

/**
 * Resolve the open order for a table (dine-in). Default routing (WS-first).
 */
export async function fetchOrderByTable(tableId: string): Promise<Order> {
  const res = await apiFetch<{ data: Order }>(
    `/api/v1/kiosk/orders?table_id=${encodeURIComponent(tableId)}`,
  );
  return normalizeOrderImages(res.data);
}

/**
 * Device-effective payment options for this kiosk (Plan 047 T6.2).
 * Cloud: GET /api/v1/kiosk/effective-payment-options.
 * LAN: workstation cloud-passthrough forwards the same path.
 */
/**
 * Read the branch's printer config from Cloud (issue #44 Phase B).
 *
 * Cloud owns the config (name / roles / LAN address); the kiosk mirrors it
 * instead of assuming the workstation knows its own printers. Pinned to Cloud
 * (`forceCloud`) because it is a Cloud-owned read: during a cloud outage this
 * fetch simply errors (advisory info for the UI) rather than routing to a
 * workstation that would answer with its SPA index.html. Byte-pushing to the
 * ESC/POS printer still goes through the workstation LAN gateway.
 */
export async function fetchKioskPrinters(): Promise<KioskPrinter[]> {
  const res = await apiFetch<{ data: KioskPrinter[] }>('/api/v1/kiosk/printers', {
    forceCloud: true,
  });
  return res.data ?? [];
}

export async function fetchEffectivePaymentOptions(): Promise<EffectivePaymentOptionsSnapshot> {
  const res = await apiFetch<{ data: EffectivePaymentOptionsSnapshot }>(
    '/api/v1/kiosk/effective-payment-options',
  );
  // The kiosk shows only rows with `effective: true`, so "no payment methods"
  // is indistinguishable from "endpoint returned nothing" on screen. Log the
  // per-option verdict — `reason` is the only thing that says WHICH policy
  // layer rejected it (unverified capability, channel, device class, ...).
  const rows = res.data?.options ?? [];
  devLog(
    `[effective-payment-options] revision=${res.data?.revision} count=${rows.length} effective=${
      rows.filter((r) => r.effective).length
    }`,
  );
  for (const r of rows) {
    devLog(
      `[effective-payment-options]   ${r.effective ? '✓' : '✗'} ${r.rail}/${r.display_name} reason=${r.reason} error_code=${r.error_code ?? '-'} conn=${r.connection_id ?? '-'}`,
    );
  }
  return res.data;
}

/**
 * By-items split preview (Plan 033). Read-only; never mutates.
 *
 * Without `allocations` it returns per-item `units_remaining` (claims summed
 * from non-failed payments). With a candidate selection it ALSO returns
 * `preview_bills` — the authoritative per-bill total (tax/discount/service +
 * shop rounding applied server-side). The kiosk computes each sub-check as a
 * standalone bill at index 0, so `preview_bills[0].total` is the amount to
 * charge for the current guest.
 */
export async function fetchSplitByItemsPreview(
  orderId: string,
  allocations?: ItemAllocation[],
): Promise<SplitByItemsPreview> {
  let path = `/api/v1/kiosk/orders/${orderId}/split-by-items/preview`;
  if (allocations && allocations.length > 0) {
    const candidate = allocations.map((a) => ({
      item_id: a.item_id,
      units: a.units,
      bill_index: 0,
    }));
    path += `?allocations=${encodeURIComponent(JSON.stringify(candidate))}`;
  }
  devLog(`[split-preview] GET ${resolveBaseUrl()}${path}`);
  try {
    const res = await apiFetch<{ data: SplitByItemsPreview }>(path);
    devLog(`[split-preview] raw data →`, JSON.stringify(res?.data));
    return res.data;
  } catch (e) {
    const status = e instanceof ApiError ? e.status : "network";
    console.warn(`[split-preview] FAILED status=${status} —`, e);
    throw e;
  }
}

/**
 * Discriminated result of decoding a scanned QR token. The backend owns the
 * token→entity mapping; the kiosk just switches on `type` to pick its route.
 *  - `table`: dine-in — fetch the open order by table id (`/bill?tableId=`).
 *  - `order`: takeaway / pre-created order — open directly by code (`/bill?orderCode=`).
 */
export type QrResolution =
  | { type: "table"; table_id: string; table_name: string }
  | { type: "order"; order_code: string; order_id?: string };

/**
 * Decode a scanned QR token via the public customer endpoint (no auth).
 *
 * A single opaque token resolves to EITHER a table or an order — the kiosk can't
 * tell them apart locally (that's the point: the token stays unguessable, unlike
 * a bare "T-01"). The backend decodes it and returns a discriminated payload:
 *   { "type": "table", "table": { "id", "code", "name" } }
 *   { "type": "order", "order": { "id", "code" } }
 */
export async function resolveQrCode(token: string): Promise<QrResolution> {
  devLog(`[resolveQrCode] token=${JSON.stringify(token)} → GET ${CLOUD_URL}/api/v1/customer/qr/${encodeURIComponent(token)}`);
  const res = await apiFetch<{
    data:
      | { type: "table"; table: { id: string; code: string; name?: string | null } }
      | { type: "order"; order: { id: string; code: string } };
  }>(`/api/v1/customer/qr/${encodeURIComponent(token)}`, { forceCloud: true });
  devLog(`[resolveQrCode] raw response →`, JSON.stringify(res));
  const d = res.data;
  if (d.type === "order") {
    return { type: "order", order_code: d.order.code, order_id: d.order.id };
  }
  return {
    type: "table",
    table_id: d.table.id,
    table_name: d.table.name ?? d.table.code,
  };
}

// Confirm records the captured payment AND drives the workstation's synchronous
// bill print, so it routinely needs more than the 3s LAN fast-fail budget. A
// premature timeout here is the worst case: the card is already charged and the
// terminal approved, but the kiosk reports an error and the order never flips to
// paid. Give it the full Cloud budget on the workstation route too.
const CONFIRM_TIMEOUT_MS = 15_000;

/**
 * Confirm a pending payment after terminal success.
 */
export async function confirmKioskPayment(
  paymentId: string,
  terminalData?: Record<string, unknown>,
  terminalRef?: string,
): Promise<{ id: string; status: string }> {
  const res = await apiFetch<{ data: { id: string; status: string } }>(
    `/api/v1/kiosk/payments/${paymentId}/confirm`,
    {
      method: 'POST',
      timeout: CONFIRM_TIMEOUT_MS,
      body: JSON.stringify({
        terminal_data: terminalData,
        terminal_ref: terminalRef,
      }),
    },
  );
  return res.data;
}

/**
 * Printing is synchronous on the workstation (it drives the ESC/POS socket and
 * waits for the slip), so the 3s LAN fast-fail budget is far too tight — a
 * printer waking from sleep routinely takes longer. There is no Cloud leg to
 * race against here, so a generous budget costs nothing but avoids reporting
 * "print failed" for a receipt that is physically coming out of the printer.
 */
const PRINT_TIMEOUT_MS = 10_000;

/**
 * Ask the workstation to print the "DA THANH TOAN" receipt for an order.
 *
 * The kiosk no longer drives a printer directly — the workstation is the single
 * print authority. It reads the order + split state from its local DB and emits
 * the paid slip (plus a "remaining" slip for splits with money still owed), so
 * the body only needs the order id.
 *
 * Pinned to the LAN (`workstationOnly`) rather than riding `resolveBaseUrl`:
 * since the kiosk went cloud-first (issue #44) the default route is Cloud, and
 * Cloud has no print endpoint at all. Without a workstation this throws
 * `WorkstationUnavailableError` so the success screen can say so out loud
 * instead of swallowing a 404.
 */
export async function printKioskReceipt(orderId: string): Promise<void> {
  await apiFetch<unknown>('/api/lan/print/payment-receipt', {
    method: 'POST',
    workstationOnly: true,
    timeout: PRINT_TIMEOUT_MS,
    body: JSON.stringify({ order_id: orderId }),
  });
}

/**
 * Fail a pending payment after terminal error.
 */
export async function failKioskPayment(
  paymentId: string,
  reason?: string,
  errorCode?: string,
): Promise<{ id: string; status: string }> {
  const res = await apiFetch<{ data: { id: string; status: string } }>(
    `/api/v1/kiosk/payments/${paymentId}/fail`,
    {
      method: 'POST',
      body: JSON.stringify({ reason, error_code: errorCode }),
    },
  );
  return res.data;
}
