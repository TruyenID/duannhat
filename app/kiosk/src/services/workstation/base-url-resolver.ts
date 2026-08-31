import AsyncStorage from "@react-native-async-storage/async-storage";
import { workstationDiscovery } from "./discovery";

export const CLOUD_URL = process.env.EXPO_PUBLIC_API_URL ?? "http://localhost:5400";

/**
 * Normalize a user/ops-entered workstation URL into something `fetch()` accepts.
 * - prepends `http://` when no scheme is present (RN fetch throws
 *   "Network request failed" on a schemeless URL like "192.168.1.249:8080")
 * - strips trailing slashes so callers can safely append `/api/...`
 * Returns null for empty input.
 */
export function normalizeWorkstationUrl(
  raw: string | null | undefined,
): string | null {
  const trimmed = raw?.trim();
  if (!trimmed) return null;
  const withScheme = /^https?:\/\//i.test(trimmed) ? trimmed : `http://${trimmed}`;
  return withScheme.replace(/\/+$/, "");
}

/** Build-time default workstation URL. A standby LAN address baked into the
 * kiosk image so ops can ship a tablet that already knows where its
 * workstation lives, without an operator typing the IP into Settings. Only
 * consulted when mDNS discovery has nothing and no manual override is set. */
const DEFAULT_WORKSTATION_URL = normalizeWorkstationUrl(
  process.env.EXPO_PUBLIC_WORKSTATION_URL,
);

const STORAGE_MANUAL_URL = "kiosk_workstation_manual_url";
const STORAGE_LAN_FALLBACK = "kiosk_workstation_lan_fallback";

/** How long to keep routing to the workstation after Cloud failed. */
const CLOUD_UNREACHABLE_BACKOFF_MS = 30_000;

/**
 * Last-known manual override (admin-entered IP in Settings). Loaded async on
 * first use and kept in memory so the synchronous resolver can read it.
 */
let manualUrlCache: string | null = null;
let manualUrlLoaded = false;
const manualUrlListeners = new Set<(url: string | null) => void>();

/**
 * LAN fallback mode. OFF by default — the kiosk is cloud-first and the
 * workstation is a standby, so nothing touches the LAN until an operator opts
 * in from Settings. Gates mDNS discovery (see `workstation-provider`), which
 * is why it lives here next to the routing decision rather than in a screen.
 */
let lanFallbackEnabled = false;
const lanFallbackListeners = new Set<(enabled: boolean) => void>();

/** Timestamp (epoch ms) until which Cloud is presumed down → prefer the LAN. */
let cloudUnreachableUntil = 0;

void hydrate();

async function hydrate(): Promise<void> {
  await Promise.all([hydrateManualUrl(), hydrateLanFallback()]);
}

async function hydrateManualUrl(): Promise<void> {
  try {
    const v = await AsyncStorage.getItem(STORAGE_MANUAL_URL);
    manualUrlCache = normalizeWorkstationUrl(v);
  } catch {
    manualUrlCache = null;
  } finally {
    manualUrlLoaded = true;
    // Notify AFTER hydration so a subscriber that mounted before AsyncStorage
    // resolved (e.g. the provider's print_status socket) picks up a stored URL.
    notifyManualUrl();
  }
}

function notifyManualUrl(): void {
  for (const l of manualUrlListeners) l(manualUrlCache);
}

/**
 * Subscribe to manual-URL changes. Fires immediately with the current value.
 * The provider uses this to open the workstation WebSocket (print_status /
 * cache-invalidation events) against a manually-configured or build-time
 * workstation even when mDNS is off — otherwise a workstation reached only via
 * a saved URL during a Cloud outage prints with no failure feedback
 * (issue #44 review finding #3).
 */
export function onManualUrlChange(cb: (url: string | null) => void): () => void {
  manualUrlListeners.add(cb);
  cb(manualUrlLoaded ? manualUrlCache : null);
  return () => {
    manualUrlListeners.delete(cb);
  };
}

async function hydrateLanFallback(): Promise<void> {
  let stored: string | null = null;
  try {
    stored = await AsyncStorage.getItem(STORAGE_LAN_FALLBACK);
  } catch {
    stored = null;
  }
  applyLanFallback(stored === "1");
}

function applyLanFallback(enabled: boolean): void {
  if (lanFallbackEnabled === enabled) return;
  lanFallbackEnabled = enabled;
  for (const listener of lanFallbackListeners) listener(enabled);
}

/**
 * LAN address of the workstation, or null when none is known.
 *
 * Order: mDNS discovery (only scans while LAN fallback mode is on) → manual
 * Settings URL → build-time default. Used for BOTH the Cloud-outage fallback
 * and for printing — printing is physically LAN-only, Cloud can't open a
 * socket to an ESC/POS printer sitting behind the restaurant's router.
 */
export function resolveWorkstationUrl(): string | null {
  const discovered = workstationDiscovery.current();
  if (discovered?.proxyUrl) return discovered.proxyUrl;

  if (manualUrlLoaded && manualUrlCache) return manualUrlCache;

  return DEFAULT_WORKSTATION_URL;
}

/**
 * Resolve the base URL for the next API call. Cloud-first, LAN fallback.
 *
 * Cloud is the source of truth; the workstation is a standby that only takes
 * over while Cloud is failing (`markCloudUnreachable` opens a 30s window). This
 * is the inverse of the original LAN-first order — see issue #44. Do NOT flip
 * it back without also revisiting the print path, which routes to the
 * workstation explicitly rather than piggybacking on this function.
 */
export function resolveBaseUrl(): string {
  // Cloud healthy (or its backoff window has expired) → Cloud, source of truth.
  if (Date.now() >= cloudUnreachableUntil) return CLOUD_URL;
  // Cloud is known-down: prefer the LAN workstation whenever one is known — it
  // is the lifeline during a cloud outage. There is intentionally NO
  // workstation backoff: honoring one here would bounce every request back onto
  // the dead Cloud leg for the full window (eating a 15s timeout each), starving
  // the only leg that might work. Retrying the workstation (3s fast-fail) is
  // cheaper and self-heals after a transient blip. See issue #44 review finding #2.
  return resolveWorkstationUrl() ?? CLOUD_URL;
}

/** Mark Cloud unreachable — subsequent calls prefer the workstation, if known. */
export function markCloudUnreachable(): void {
  cloudUnreachableUntil = Date.now() + CLOUD_UNREACHABLE_BACKOFF_MS;
}

/**
 * Reset the Cloud-unreachable flag — call on network change or manual retry.
 *
 * There is deliberately no workstation-unreachable backoff: the workstation is
 * only ever routed to WHILE Cloud is down, and skipping it then would just
 * bounce traffic back onto the dead Cloud leg (issue #44 review finding #2).
 */
export function resetUnreachable(): void {
  cloudUnreachableUntil = 0;
}

/** True if the current call would route through workstation (not Cloud). */
export function isUsingWorkstation(): boolean {
  return resolveBaseUrl() !== CLOUD_URL;
}

/** Synchronous read of the LAN fallback opt-in (false until hydrated). */
export function isLanFallbackEnabled(): boolean {
  return lanFallbackEnabled;
}

/**
 * Whether the provider should run mDNS discovery right now. Discovery — and
 * therefore continuous `_ws-app._tcp` probing on the restaurant LAN — is gated
 * on the LAN opt-in so a cloud-first kiosk touches the LAN only when an operator
 * asked for it (issue #44 Phase A). Exposed as a pure predicate so the gating
 * invariant is unit-testable without rendering the provider (review finding #10).
 */
export function shouldScanWorkstation(
  isAuthenticated: boolean,
  branchId: string,
): boolean {
  return isAuthenticated && branchId.length > 0 && lanFallbackEnabled;
}

export async function setLanFallbackEnabled(enabled: boolean): Promise<void> {
  applyLanFallback(enabled);
  // A mode switch invalidates both backoffs: turning it ON lets the next
  // failure reach the LAN immediately, turning it OFF must pull traffic back to
  // Cloud without waiting out the 30s window.
  resetUnreachable();
  if (enabled) {
    await AsyncStorage.setItem(STORAGE_LAN_FALLBACK, "1");
  } else {
    await AsyncStorage.removeItem(STORAGE_LAN_FALLBACK);
  }
}

/** Subscribe to LAN-fallback changes. Fires immediately with the current value. */
export function onLanFallbackChange(
  cb: (enabled: boolean) => void,
): () => void {
  lanFallbackListeners.add(cb);
  cb(lanFallbackEnabled);
  return () => {
    lanFallbackListeners.delete(cb);
  };
}

export async function getManualUrl(): Promise<string | null> {
  if (!manualUrlLoaded) await hydrateManualUrl();
  return manualUrlCache;
}

export async function setManualUrl(url: string | null): Promise<void> {
  const normalized = normalizeWorkstationUrl(url);
  manualUrlCache = normalized;
  manualUrlLoaded = true;
  notifyManualUrl();
  if (normalized) {
    await AsyncStorage.setItem(STORAGE_MANUAL_URL, normalized);
  } else {
    await AsyncStorage.removeItem(STORAGE_MANUAL_URL);
  }
}
