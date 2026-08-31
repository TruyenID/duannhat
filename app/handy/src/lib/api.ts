// #324 — storage wrapper: SecureStore on native, localStorage on web.
import * as storage from './secure-storage';
import { getWorkstationUrl } from './auth';

export class ApiError extends Error {
  constructor(
    public status: number,
    public body: unknown,
  ) {
    super(`API ${status}`);
    this.name = 'ApiError';
  }
}

// Listeners registered by _layout to handle 401 → redirect to /pair
type UnauthorizedListener = () => void;
const unauthorizedListeners = new Set<UnauthorizedListener>();

export function onUnauthorized(fn: UnauthorizedListener): () => void {
  unauthorizedListeners.add(fn);
  return () => unauthorizedListeners.delete(fn);
}

function notifyUnauthorized() {
  unauthorizedListeners.forEach((fn) => fn());
}

// Listeners for a device-type 403 (code DEVICE_TYPE_NOT_ALLOWED) → the paired
// device is the wrong type for Handy; clear the token and send back to /pair.
type ForbiddenListener = () => void;
const forbiddenListeners = new Set<ForbiddenListener>();

export function onForbidden(fn: ForbiddenListener): () => void {
  forbiddenListeners.add(fn);
  return () => forbiddenListeners.delete(fn);
}

function notifyForbidden() {
  forbiddenListeners.forEach((fn) => fn());
}

export async function apiFetch<T>(path: string, options?: RequestInit): Promise<T> {
  const token = await storage.getItem('device_token');
  // After pairing, use the stored workstation URL; fall back to env (Cloud) only if not set.
  const wsUrl = await getWorkstationUrl();
  const baseUrl = wsUrl ?? process.env.EXPO_PUBLIC_API_URL ?? '';

  const response = await fetch(`${baseUrl}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'Accept-Language': 'ja',
      ...(token && { Authorization: `Bearer ${token}` }),
      ...options?.headers,
    },
  });

  if (!response.ok) {
    const body = await response.json().catch(() => ({}));
    if (response.status === 401) {
      notifyUnauthorized();
    } else if (
      response.status === 403 &&
      (body as { code?: string })?.code === 'DEVICE_TYPE_NOT_ALLOWED'
    ) {
      notifyForbidden();
    }
    throw new ApiError(response.status, body);
  }
  if (response.status === 204) return null as T;
  return response.json();
}
