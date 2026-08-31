import * as SecureStore from "expo-secure-store";
import { Platform } from "react-native";
import AsyncStorage from "@react-native-async-storage/async-storage";

const WORKSTATION_URL_KEY = "pos_shell_workstation_url";

async function setItem(key: string, value: string): Promise<void> {
  if (Platform.OS === "web") {
    await AsyncStorage.setItem(key, value);
    return;
  }
  await SecureStore.setItemAsync(key, value);
}

async function getItem(key: string): Promise<string | null> {
  if (Platform.OS === "web") {
    return AsyncStorage.getItem(key);
  }
  return SecureStore.getItemAsync(key);
}

async function deleteItem(key: string): Promise<void> {
  if (Platform.OS === "web") {
    await AsyncStorage.removeItem(key);
    return;
  }
  await SecureStore.deleteItemAsync(key);
}

export async function getStoredWorkstationUrl(): Promise<string | null> {
  return getItem(WORKSTATION_URL_KEY);
}

export async function setStoredWorkstationUrl(url: string): Promise<void> {
  await setItem(WORKSTATION_URL_KEY, url);
}

export async function clearStoredWorkstationUrl(): Promise<void> {
  await deleteItem(WORKSTATION_URL_KEY);
}

/** Build-time fallback for local dev (optional). */
export function envWorkstationUrl(): string | null {
  const raw = process.env.EXPO_PUBLIC_WORKSTATION_URL?.trim();
  return raw ? raw.replace(/\/+$/, "") : null;
}
