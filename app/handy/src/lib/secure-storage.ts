/**
 * #324 — expo-secure-store has NO web implementation: on `expo start --web`
 * every call throws `ExpoSecureStore.default.getValueWithKeyAsync is not a
 * function` and the app dies at the auth bootstrap. This wrapper keeps the
 * native path on SecureStore (hardware-backed) and falls back to
 * localStorage on web — the handy web build is a dev/preview convenience,
 * not a production surface, so plaintext localStorage is acceptable there
 * (same posture as pos-web's token storage).
 */

import { Platform } from 'react-native';
import * as SecureStore from 'expo-secure-store';

const isWeb = Platform.OS === 'web';

export async function getItem(key: string): Promise<string | null> {
  if (isWeb) {
    try {
      return window.localStorage.getItem(key);
    } catch {
      return null;
    }
  }
  return SecureStore.getItemAsync(key);
}

export async function setItem(key: string, value: string): Promise<void> {
  if (isWeb) {
    try {
      window.localStorage.setItem(key, value);
    } catch {
      // Storage full / privacy mode — the caller treats persistence as
      // best-effort; the session still works in memory.
    }
    return;
  }
  await SecureStore.setItemAsync(key, value);
}

export async function deleteItem(key: string): Promise<void> {
  if (isWeb) {
    try {
      window.localStorage.removeItem(key);
    } catch {
      // ignore
    }
    return;
  }
  await SecureStore.deleteItemAsync(key);
}
