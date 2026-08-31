/**
 * Cross-platform device token storage.
 *
 * On native (iOS/Android) uses expo-secure-store (Keychain / Keystore).
 * On web, expo-secure-store ships an empty stub, so we fall back to
 * @react-native-async-storage/async-storage (IndexedDB-backed). Web is
 * dev/testing only — the kiosk ships on tablets.
 */

import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import { Platform } from 'react-native';

const TOKEN_KEY = 'tms_device_token';

const isWeb = Platform.OS === 'web';

export async function getDeviceToken(): Promise<string | null> {
  return isWeb ? AsyncStorage.getItem(TOKEN_KEY) : SecureStore.getItemAsync(TOKEN_KEY);
}

export async function setDeviceToken(token: string): Promise<void> {
  if (isWeb) {
    await AsyncStorage.setItem(TOKEN_KEY, token);
  } else {
    await SecureStore.setItemAsync(TOKEN_KEY, token);
  }
}

export async function clearDeviceToken(): Promise<void> {
  if (isWeb) {
    await AsyncStorage.removeItem(TOKEN_KEY);
  } else {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
  }
}
