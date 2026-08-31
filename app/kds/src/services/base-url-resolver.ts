/**
 * Resolves the base URL for the next API call based on:
 *   - User's preferred API mode (Settings → Auto/Workstation/Cloud)
 *   - Workstation reachability (auto mode falls back to Cloud after timeout)
 *
 * Cloud is always reachable as a fallback. Workstation is LAN-only and may
 * go down during a shift — auto mode handles that gracefully with a short
 * backoff window so we don't hammer a dead workstation on every call.
 */

export const CLOUD_URL =
  import.meta.env.VITE_API_URL || "http://localhost:5400";
export const WORKSTATION_URL =
  import.meta.env.VITE_WORKSTATION_API_URL || "http://localhost:8080";

export type ApiMode = "auto" | "workstation" | "cloud";

const STORAGE_MODE = "kds_api_mode";
const UNREACHABLE_BACKOFF_MS = 30_000;

/** Mutable runtime state. Single-process JS so no need for locks. */
let unreachableUntil = 0;

export function getMode(): ApiMode {
  if (typeof localStorage === "undefined") return "auto";
  const stored = localStorage.getItem(STORAGE_MODE);
  if (stored === "workstation" || stored === "cloud") return stored;
  return "auto";
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
  if (mode === "cloud") return { url: CLOUD_URL, via: "cloud" };
  if (mode === "workstation") return { url: WORKSTATION_URL, via: "workstation" };

  // auto: skip workstation while in backoff
  if (Date.now() < unreachableUntil) return { url: CLOUD_URL, via: "cloud" };
  return { url: WORKSTATION_URL, via: "workstation" };
}

/** Mark workstation unreachable for UNREACHABLE_BACKOFF_MS (auto-mode only). */
export function markWorkstationUnreachable(): void {
  unreachableUntil = Date.now() + UNREACHABLE_BACKOFF_MS;
}

/**
 * Clear the backoff — call when network state changes (online event) or when
 * the user manually requests a connection test from Settings.
 */
export function resetUnreachable(): void {
  unreachableUntil = 0;
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
