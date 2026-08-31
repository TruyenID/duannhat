import { LOCALE_STORAGE_KEY } from "@/i18n";
import {
  CLOUD_URL,
  getWorkstationUrl,
  isServedByWorkstation,
} from "@/services/workstation/base-url-resolver";

/** Same key AppProvider persists; mirrors apiFetch's own locale read. */
function getLocale(): string {
  if (typeof localStorage === "undefined") return "ja";
  return localStorage.getItem(LOCALE_STORAGE_KEY) ?? "ja";
}

export interface PairRequest {
  pairing_code: string;
  device_info: {
    user_agent: string;
    app_version: string;
  };
}

export interface PairResponse {
  device_token: string;
  device: {
    id: string;
    name: string;
    type: string;
    branch_id: string;
    organization_id?: string;
    paired_at?: string;
    branch?: { id: string; name: string; slug?: string };
  };
}

export class PairError extends Error {
  readonly status: number;
  readonly body: unknown;
  constructor(status: number, body: unknown, message: string) {
    super(message);
    this.status = status;
    this.body = body;
  }
}

// Pairing runs BEFORE any device token exists, so it can't go through apiFetch
// (which would stamp an auth header and 401-redirect). It must reach Cloud —
// but HOW depends on where pos-web is served from:
//   • Amplify / dev  → hit Cloud directly at CLOUD_URL.
//   • embedded in the workstation (/pos) → the page origin is the workstation's
//     LAN IP, so a direct cross-origin POST to CLOUD_URL is CORS-blocked (Cloud
//     never allowlists arbitrary shop LAN origins). Go SAME-ORIGIN through the
//     workstation instead; it relays POST /api/v1/devices/pair to Cloud. (#1481)
export async function pairDevice(req: PairRequest): Promise<PairResponse> {
  const base = isServedByWorkstation() ? getWorkstationUrl() : CLOUD_URL;
  // eslint-disable-next-line no-restricted-globals -- pairing runs BEFORE any token exists; apiFetch would stamp auth headers and 401-redirect
  const res = await fetch(`${base}/api/v1/devices/pair`, {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      // Cloud resolves `__('Invalid or expired pairing code.')` against this;
      // without it Laravel falls back to APP_LOCALE (en) and a Japanese
      // cashier reads an English error on the one screen they cannot get
      // past. apiFetch has always sent it — pairing hand-rolls its own fetch
      // (pre-token) and simply forgot.
      "Accept-Language": getLocale(),
    },
    body: JSON.stringify({
      ...req,
      // Fail closed on type mismatch at Cloud instead of burning a TMS/kiosk
      // code into a POS session.
      expected_type: "pos",
    }),
  });
  if (!res.ok) {
    const body = (await res.json().catch(() => ({}))) as {
      message?: string;
      detail?: string;
      errors?: Record<string, string[] | string>;
    };
    // Prefer the most specific field error (e.g. pairing_code type mismatch)
    // over a generic top-level message so the pairing screen can show it.
    let detail = body.message ?? body.detail ?? "";
    if (body.errors && typeof body.errors === "object") {
      for (const value of Object.values(body.errors)) {
        if (Array.isArray(value) && typeof value[0] === "string" && value[0]) {
          detail = value[0];
          break;
        }
        if (typeof value === "string" && value) {
          detail = value;
          break;
        }
      }
    }
    throw new PairError(
      res.status,
      body,
      detail || `Pair failed: HTTP ${res.status}`,
    );
  }
  return res.json() as Promise<PairResponse>;
}
