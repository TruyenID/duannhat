import * as Application from "expo-application";
import * as Device from "expo-device";
import { Platform } from "react-native";

import {
  ApiError,
  createApiFetch,
  createDeviceTokenStorage,
  createLocaleStorage,
  type PaginatedResponse,
} from "@godxjp/mobile-shared";

export { ApiError };
export type { PaginatedResponse };

/** Token storage backed by Keychain/Keystore on native, AsyncStorage on web. */
export const tokenStorage = createDeviceTokenStorage("tms_device_token");

/** Locale storage backed by AsyncStorage on every platform. */
export const localeStorage = createLocaleStorage({
  key: "tms_locale",
  defaultLocale: "ja",
});

const API_BASE = process.env.EXPO_PUBLIC_API_URL ?? "http://localhost:5400";

/** Single shared fetch wrapper — sends bearer token + Accept-Language. */
const baseApiFetch = createApiFetch({
  baseUrl: API_BASE,
  resolvers: {
    getToken: () => tokenStorage.get(),
    getLocale: () => localeStorage.get(),
    onUnauthorized: () => tokenStorage.clear(),
  },
});

/**
 * #935 — mobile-shared only clears the token on 401. A 403 with
 * code DEVICE_TYPE_NOT_ALLOWED (wrong device type paired via an old backend)
 * is just as unrecoverable for the TMS surface, so give it the same exit:
 * clear the token so the next auth check lands on the pairing screen instead
 * of every list silently rendering empty.
 */
export const apiFetch: typeof baseApiFetch = async (path, init) => {
  try {
    return await baseApiFetch(path, init);
  } catch (e) {
    if (
      e instanceof ApiError &&
      e.status === 403 &&
      (e.body as { code?: string })?.code === "DEVICE_TYPE_NOT_ALLOWED"
    ) {
      await tokenStorage.clear();
    }
    throw e;
  }
};

/** Legacy named-export shims — many call sites import these directly. */
export function getDeviceToken(): Promise<string | null> {
  return tokenStorage.get();
}

export function setDeviceToken(token: string): Promise<void> {
  return tokenStorage.set(token);
}

export function clearDeviceToken(): Promise<void> {
  return tokenStorage.clear();
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
      // #935 — mirror the device.auth:tms allow-set: the backend rejects a
      // code belonging to any other device type with a clear 422 BEFORE
      // mutating, so the code stays usable by the right app.
      expected_type: "tms",
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
 * #935 — thrown by the pair success-path guard when an OLD backend (without
 * the expected_type gate) hands back a non-tms device. The token is never
 * stored; the login screen shows a wrong-device-type message.
 */
export class DeviceTypeMismatchError extends Error {
  constructor(public actualType: string) {
    super('Pairing code belongs to a "' + actualType + '" device, not a TMS terminal');
  }
}
