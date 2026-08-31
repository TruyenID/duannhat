import AsyncStorage from '@react-native-async-storage/async-storage';

/**
 * Cross-platform locale storage backed by AsyncStorage.
 *
 * Locale is read on every API call by `apiFetch` (via the `getLocale` resolver)
 * to populate the `Accept-Language` header. Any change here should be visible
 * to the next request without a restart, so writes are awaited synchronously.
 */
export interface LocaleStorage {
  get(): Promise<string>;
  set(locale: string): Promise<void>;
}

export interface CreateLocaleStorageOptions {
  key: string;
  /** Returned when nothing has been stored yet. Apps default to `'ja'` typically. */
  defaultLocale: string;
}

export function createLocaleStorage(options: CreateLocaleStorageOptions): LocaleStorage {
  const { key, defaultLocale } = options;
  return {
    async get() {
      return (await AsyncStorage.getItem(key)) ?? defaultLocale;
    },
    async set(locale: string) {
      await AsyncStorage.setItem(key, locale);
    },
  };
}
