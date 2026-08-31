/**
 * Cross-platform secure storage for a single bearer token.
 *
 * Native: Keychain (iOS) / Keystore (Android) via `expo-secure-store`.
 * Web:    `AsyncStorage` (which on web is IndexedDB-backed). `expo-secure-store`
 *         ships an empty stub for web that throws on every call, so the web
 *         fallback is mandatory if the consuming app supports `npx expo start --web`.
 *
 * Both `expo-secure-store` and `@react-native-async-storage/async-storage` are
 * declared as **optional** peer dependencies of this package — apps may opt
 * out of either. On web-only consumers, AsyncStorage is enough. On Android/iOS
 * production builds, SecureStore is enough. Most apps need both.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

export interface DeviceTokenStorage {
  get(): Promise<string | null>;
  set(token: string): Promise<void>;
  clear(): Promise<void>;
}

/**
 * Builds a token storage instance scoped to a particular key.
 *
 * Each app should pick a unique key prefix so two apps installed on the same
 * device (e.g. tms-app and kiosk on the same tablet) cannot read each other's
 * tokens — even though Keychain entries are typically already namespaced by
 * bundle id, the explicit key keeps the AsyncStorage web fallback safe too.
 */
export function createDeviceTokenStorage(key: string): DeviceTokenStorage {
  const isWeb = Platform.OS === 'web';

  return {
    async get() {
      return isWeb ? AsyncStorage.getItem(key) : SecureStore.getItemAsync(key);
    },
    async set(token: string) {
      if (isWeb) {
        await AsyncStorage.setItem(key, token);
      } else {
        await SecureStore.setItemAsync(key, token);
      }
    },
    async clear() {
      if (isWeb) {
        await AsyncStorage.removeItem(key);
      } else {
        await SecureStore.deleteItemAsync(key);
      }
    },
  };
}
