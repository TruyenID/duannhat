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

/** Build-time default workstation URL. Used when AsyncStorage has no manual
 * override AND mDNS discovery is unavailable (web / Expo Go). Lets ops ship
 * a kiosk image pre-pointed at a known workstation without forcing every
 * operator to enter the URL via Settings. */
const DEFAULT_WORKSTATION_URL = normalizeWorkstationUrl(
  process.env.EXPO_PUBLIC_WORKSTATION_URL,
);

const STORAGE_MANUAL_URL = "kiosk_workstation_manual_url";
const UNREACHABLE_BACKOFF_MS = 30_000;

/**
 * Last-known manual override (admin-entered IP in Settings). Loaded async on
 * first use and kept in memory so the synchronous resolver can read it.
 */
let manualUrlCache: string | null = null;
let manualUrlLoaded = false;

/** Timestamp (epoch ms) until which we should skip workstation routing. */
let unreachableUntil = 0;

void hydrateManualUrl();

async function hydrateManualUrl(): Promise<void> {
  try {
    const v = await AsyncStorage.getItem(STORAGE_MANUAL_URL);
    manualUrlCache = normalizeWorkstationUrl(v);
  } catch {
    manualUrlCache = null;
  } finally {
    manualUrlLoaded = true;
  }
}

/** Resolve the base URL for the next API call. LAN-first, Cloud fallback. */
export function resolveBaseUrl(): string {
  if (Date.now() < unreachableUntil) return CLOUD_URL;

  const discovered = workstationDiscovery.current();
  if (discovered?.proxyUrl) return discovered.proxyUrl;

  if (manualUrlLoaded && manualUrlCache) return manualUrlCache;

  if (DEFAULT_WORKSTATION_URL) return DEFAULT_WORKSTATION_URL;

  return CLOUD_URL;
}

/** Mark workstation unreachable for UNREACHABLE_BACKOFF_MS. Subsequent calls hit Cloud. */
export function markWorkstationUnreachable(): void {
  unreachableUntil = Date.now() + UNREACHABLE_BACKOFF_MS;
}

/** Reset the unreachable flag — call on network change or manual retry. */
export function resetUnreachable(): void {
  unreachableUntil = 0;
}

/** True if the current call would route through workstation (not Cloud). */
export function isUsingWorkstation(): boolean {
  return resolveBaseUrl() !== CLOUD_URL;
}

export async function getManualUrl(): Promise<string | null> {
  if (!manualUrlLoaded) await hydrateManualUrl();
  return manualUrlCache;
}

export async function setManualUrl(url: string | null): Promise<void> {
  const normalized = normalizeWorkstationUrl(url);
  manualUrlCache = normalized;
  manualUrlLoaded = true;
  if (normalized) {
    await AsyncStorage.setItem(STORAGE_MANUAL_URL, normalized);
  } else {
    await AsyncStorage.removeItem(STORAGE_MANUAL_URL);
  }
}
