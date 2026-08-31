export interface DeviceInfo {
  id: string;
  name: string;
  type: string;
  branch_id: string;
  branch_name?: string;
  /**
   * Slug of the branch this device is pinned to (server-side at pairing time).
   * Drives the `/` → `/shop/:slug` redirect so a paired terminal opens on its
   * own shop instead of a build-time default (see App.tsx DefaultShopRedirect).
   */
  branch_slug?: string;
  organization_id?: string;
  paired_at?: string;
}

const TOKEN_KEY = "pos_device_token";
const DEVICE_KEY = "pos_device_info";

export function getToken(): string | null {
  if (typeof window === "undefined") return null;
  return localStorage.getItem(TOKEN_KEY);
}

export function getDeviceInfo(): DeviceInfo | null {
  if (typeof window === "undefined") return null;
  try {
    const raw = localStorage.getItem(DEVICE_KEY);
    return raw ? (JSON.parse(raw) as DeviceInfo) : null;
  } catch {
    return null;
  }
}

export function persistSession(token: string, device: DeviceInfo): void {
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(DEVICE_KEY, JSON.stringify(device));
}

export function clearSession(): void {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(DEVICE_KEY);
}
