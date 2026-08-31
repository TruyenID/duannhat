const TOKEN_KEY = "kds_device_token";
const INFO_KEY = "kds_device_info";

export interface DeviceInfo {
  id: string;
  name: string;
  type: "kds";
  branch_id: string;
  branch_name: string;
  organization_id?: string;
  paired_at?: string;
}

export function getDeviceToken(): string | null {
  return localStorage.getItem(TOKEN_KEY);
}

export function getDeviceInfo(): DeviceInfo | null {
  const raw = localStorage.getItem(INFO_KEY);
  if (!raw) return null;
  try {
    return JSON.parse(raw) as DeviceInfo;
  } catch {
    return null;
  }
}

export function setDeviceToken(token: string, info: DeviceInfo): void {
  localStorage.setItem(TOKEN_KEY, token);
  localStorage.setItem(INFO_KEY, JSON.stringify(info));
}

export function clearDeviceToken(): void {
  localStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(INFO_KEY);
}

export function isPaired(): boolean {
  return getDeviceToken() !== null && getDeviceInfo() !== null;
}
