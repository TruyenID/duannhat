import { useContext } from 'react';
import { UIContext } from './ui-context';
import type { UILocaleConfig, LocaleCode, LocaleMap, Theme, TranslatableConfig } from './ui-context';

// ─── Hooks ────────────────────────────────────────────────────────────────────

/** Access theme and setTheme. Must be inside UIProvider. */
export function useTheme(): { theme: Theme; setTheme: (t: Theme) => void } {
  const ctx = useContext(UIContext);
  if (!ctx) throw new Error('useTheme must be used within UIProvider');
  return { theme: ctx.theme, setTheme: ctx.setTheme };
}

/**
 * Returns the locale config from UIProvider.
 * Returns `undefined` when no `locales` prop was passed to UIProvider.
 */
export function useUILocales(): UILocaleConfig | undefined {
  return useContext(UIContext)?.locale;
}

/**
 * Returns the active locale state and locale config from UIProvider.
 * Must be used inside UIProvider.
 */
export function useLocale(): {
  currentLocale: LocaleCode;
  setLocale: (locale: LocaleCode) => void;
  locales: LocaleMap;
  defaultLocale: LocaleCode;
  fallbackLocale: LocaleCode;
} {
  const ctx = useContext(UIContext);
  if (!ctx) throw new Error('useLocale must be used within UIProvider');
  const config = ctx.locale ?? { locales: {}, defaultLocale: '', fallbackLocale: '' };
  return {
    currentLocale: ctx.currentLocale,
    setLocale: ctx.setLocale,
    locales: config.locales,
    defaultLocale: config.defaultLocale,
    fallbackLocale: config.fallbackLocale,
  };
}

/**
 * Returns the active timezone and setter from UIProvider.
 * Must be used inside UIProvider.
 */
export function useTimezone(): {
  timezone: string;
  setTimezone: (tz: string) => void;
} {
  const ctx = useContext(UIContext);
  if (!ctx) throw new Error('useTimezone must be used within UIProvider');
  return { timezone: ctx.timezone, setTimezone: ctx.setTimezone };
}

/**
 * Returns the date-fns `Locale` object from UIProvider.
 * Returns `undefined` when no `dateFnsLocale` prop was passed.
 */
export function useDateFnsLocale(): object | undefined {
  return useContext(UIContext)?.dateFnsLocale;
}

/**
 * Resolves the effective UILocaleConfig for a translatable field.
 * Merges inline `TranslatableConfig` with the provider's locale config.
 */
export function resolveTranslatableConfig(
  translatable: TranslatableConfig,
  providerLocales: UILocaleConfig | undefined,
): UILocaleConfig | undefined {
  if (translatable === true) {
    return providerLocales;
  }
  const base = providerLocales ?? { locales: {}, defaultLocale: '', fallbackLocale: '' };
  const merged: UILocaleConfig = {
    locales: translatable.locales ?? base.locales,
    defaultLocale: translatable.defaultLocale ?? base.defaultLocale,
    fallbackLocale: translatable.fallbackLocale ?? base.fallbackLocale,
  };
  return Object.keys(merged.locales).length > 0 ? merged : undefined;
}
