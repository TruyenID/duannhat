import * as Application from "expo-application";
import * as Device from "expo-device";
import AsyncStorage from "@react-native-async-storage/async-storage";
import { Platform } from "react-native";
import type { ItemAllocation, Order, SplitByItemsPreview } from "../types/kiosk";

import {
  getDeviceToken,
  setDeviceToken as setDeviceTokenRaw,
  clearDeviceToken,
} from "./device-token";
import {
  CLOUD_URL,
  markWorkstationUnreachable,
  resolveBaseUrl,
} from "../services/workstation/base-url-resolver";

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
      if (response.status === 401 && !options?.suppressUnauthorizedHandler) {
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

export async function apiFetch<T>(
  path: string,
  options?: RequestInit & {
    timeout?: number;
    suppressUnauthorizedHandler?: boolean;
    forceCloud?: boolean;
  },
): Promise<T> {
  const [token, locale] = await Promise.all([
    getDeviceToken(),
    AsyncStorage.getItem(STORAGE_LOCALE),
  ]);

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
    // Only fall back when workstation routing failed with a network error.
    // ApiError (4xx/5xx) is an authoritative response — don't double-call Cloud.
    if (usingWorkstation && isNetworkError(e)) {
      markWorkstationUnreachable();
      return await doFetch<T>(CLOUD_URL, path, token, locale, options, DEFAULT_TIMEOUT);
    }
    throw e;
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
  console.log(`[order-fetch] GET ${resolveBaseUrl()}${path}`);
  try {
    const res = await apiFetch<{ data: Order }>(path);
    console.log(
      `[order-fetch] ok — keys=[${res.data ? Object.keys(res.data).join(",") : "null"}] items=${
        Array.isArray(res.data?.items) ? `array(${res.data.items.length})` : typeof res.data?.items
      }`,
    );
    console.log(`[order-fetch] raw data →`, JSON.stringify(res.data));
    return res.data;
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
  console.log(`[split-preview] GET ${resolveBaseUrl()}${path}`);
  try {
    const res = await apiFetch<{ data: SplitByItemsPreview }>(path);
    console.log(`[split-preview] raw data →`, JSON.stringify(res?.data));
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
  console.log(`[resolveQrCode] token=${JSON.stringify(token)} → GET ${CLOUD_URL}/api/v1/customer/qr/${encodeURIComponent(token)}`);
  const res = await apiFetch<{
    data:
      | { type: "table"; table: { id: string; code: string; name?: string | null } }
      | { type: "order"; order: { id: string; code: string } };
  }>(`/api/v1/customer/qr/${encodeURIComponent(token)}`, { forceCloud: true });
  console.log(`[resolveQrCode] raw response →`, JSON.stringify(res));
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
 * Ask the workstation to print the "DA THANH TOAN" receipt for an order.
 *
 * The kiosk no longer drives a printer directly — the workstation is the single
 * print authority. It reads the order + split state from its local DB and emits
 * the paid slip (plus a "remaining" slip for splits with money still owed), so
 * the body only needs the order id. Routed through the workstation LAN gateway
 * by `resolveBaseUrl` (cloud has no such endpoint — see isWorkstationOnlyPath).
 */
export async function printKioskReceipt(orderId: string): Promise<void> {
  await apiFetch<unknown>('/api/lan/print/payment-receipt', {
    method: 'POST',
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
