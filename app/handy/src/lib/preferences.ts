import AsyncStorage from '@react-native-async-storage/async-storage';
import type { ColorSchemeOverride } from '@/hooks/use-color-scheme';
import type { Locale } from '@/i18n/index';

const KEYS = {
  theme: 'pref:theme',
  locale: 'pref:locale',
} as const;

export async function loadThemePreference(): Promise<ColorSchemeOverride> {
  const value = await AsyncStorage.getItem(KEYS.theme);
  if (value === 'light' || value === 'dark' || value === 'system') return value;
  return 'light';
}

export async function saveThemePreference(value: ColorSchemeOverride): Promise<void> {
  await AsyncStorage.setItem(KEYS.theme, value);
}

export async function loadLocalePreference(): Promise<Locale> {
  const value = await AsyncStorage.getItem(KEYS.locale);
  if (value === 'ja' || value === 'vi') return value;
  return 'ja';
}

export async function saveLocalePreference(locale: Locale): Promise<void> {
  await AsyncStorage.setItem(KEYS.locale, locale);
}

export async function loadAllPreferences(): Promise<{
  theme: ColorSchemeOverride;
  locale: Locale;
}> {
  const [theme, locale] = await Promise.all([
    loadThemePreference(),
    loadLocalePreference(),
  ]);
  return { theme, locale };
}