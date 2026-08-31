// #324 — storage wrapper: SecureStore on native, localStorage on web.
import * as storage from './secure-storage';

const KEY_TOKEN = 'device_token';
const KEY_DEVICE = 'device_info';
const KEY_WS_URL = 'workstation_url';

export interface StoredDevice {
  id: string;
  name: string;
  type: string;
  branch_id: string;
  branch: { id: string; name: string; slug?: string };
  shopSlug: string;
}

export async function getToken(): Promise<string | null> {
  return storage.getItem(KEY_TOKEN);
}

export async function setToken(token: string, device: StoredDevice, workstationUrl?: string): Promise<void> {
  await storage.setItem(KEY_TOKEN, token);
  await storage.setItem(KEY_DEVICE, JSON.stringify(device));
  if (workstationUrl) {
    await storage.setItem(KEY_WS_URL, workstationUrl.replace(/\/+$/, ''));
  }
}

export async function clearToken(): Promise<void> {
  await storage.deleteItem(KEY_TOKEN);
  await storage.deleteItem(KEY_DEVICE);
  await storage.deleteItem(KEY_WS_URL);
}

export async function getWorkstationUrl(): Promise<string | null> {
  // Build-time env takes priority so dev builds always point at the configured host.
  const env = process.env.EXPO_PUBLIC_WS_URL?.trim();
  if (env) return env;
  return storage.getItem(KEY_WS_URL);
}

export async function getStoredDevice(): Promise<StoredDevice | null> {
  const raw = await storage.getItem(KEY_DEVICE);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as StoredDevice;
  } catch {
    return null;
  }
}
