import {
  resolveBaseUrl,
  markWorkstationUnreachable,
} from "@/services/base-url-resolver";
import { getDeviceToken } from "@/services/auth/device-token";

export class ApiError extends Error {
  readonly status: number;
  readonly body: unknown;
  readonly code: string | null;
  readonly detail: string | null;
  readonly context: Record<string, unknown> | null;
  readonly remediation: string | null;

  constructor(status: number, body: unknown, message: string) {
    super(message);
    this.status = status;
    this.body = body;

    if (body && typeof body === "object") {
      const b = body as Record<string, unknown>;
      this.code = typeof b.code === "string" ? b.code : null;
      this.detail = typeof b.detail === "string" ? b.detail : null;
      this.context =
        b.context !== null && typeof b.context === "object"
          ? (b.context as Record<string, unknown>)
          : null;
      this.remediation =
        typeof b.remediation === "string" ? b.remediation : null;
    } else {
      this.code = null;
      this.detail = null;
      this.context = null;
      this.remediation = null;
    }
  }
}

interface ApiFetchOptions extends Omit<RequestInit, "headers"> {
  headers?: Record<string, string>;
  token?: string; // explicit override, defaults to localStorage
  baseUrl?: string; // explicit override, defaults to resolveBaseUrl()
  silent401?: boolean; // don't bubble 401 to global handler (for heartbeat polls)
}

// Callback registry — AuthProvider sets this on mount so 401 anywhere
// triggers logout flow.
let onUnauthorized: (() => void) | null = null;
export function setUnauthorizedHandler(fn: (() => void) | null) {
  onUnauthorized = fn;
}

export async function apiFetch<T = unknown>(
  path: string,
  opts: ApiFetchOptions = {},
): Promise<T> {
  const {
    token: explicitToken,
    baseUrl: explicitBase,
    silent401,
    headers: extraHeaders,
    ...rest
  } = opts;

  const target = explicitBase
    ? { url: explicitBase, via: "cloud" as const }
    : resolveBaseUrl();

  const token = explicitToken ?? getDeviceToken();
  const locale = localStorage.getItem("kds_locale") ?? "ja";

  const headers: Record<string, string> = {
    Accept: "application/json",
    "Accept-Language": locale,
    ...extraHeaders,
  };
  if (token) {
    headers["Authorization"] = `Bearer ${token}`;
  }
  if (rest.body && !(rest.body instanceof FormData)) {
    headers["Content-Type"] = headers["Content-Type"] ?? "application/json";
  }

  const url = path.startsWith("http") ? path : `${target.url}${path}`;

  let res: Response;
  try {
    res = await fetch(url, { ...rest, headers });
  } catch (err) {
    // Network error — if we were going via workstation, mark unreachable for backoff
    if (target.via === "workstation") {
      markWorkstationUnreachable();
    }
    throw new ApiError(0, null, (err as Error).message ?? "network error");
  }

  if (!res.ok) {
    const body = (await res.json().catch(() => null)) as Record<
      string,
      unknown
    > | null;
    // #935 — DEVICE_TYPE_NOT_ALLOWED means this token can never serve the
    // KDS surface (wrong device type paired via an old backend). Same exit
    // as a dead token: back to pairing instead of a silent empty board.
    const wrongDeviceType =
      res.status === 403 && body?.code === "DEVICE_TYPE_NOT_ALLOWED";
    if ((res.status === 401 || wrongDeviceType) && !silent401 && onUnauthorized) {
      onUnauthorized();
    }

    // Prefer RFC 7807 detail, fallback to legacy message, finally status code
    const errorMessage =
      (typeof body?.detail === "string" ? body.detail : null) ??
      (typeof body?.message === "string" ? body.message : null) ??
      `${res.status}`;

    throw new ApiError(res.status, body, errorMessage);
  }

  // 204 No Content
  if (res.status === 204) return undefined as T;

  return res.json() as Promise<T>;
}
