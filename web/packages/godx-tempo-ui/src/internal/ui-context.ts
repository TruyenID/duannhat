import { createContext } from 'react';

// ─── Theme ────────────────────────────────────────────────────────────────────

export type Theme = 'light' | 'dark' | 'system';

// ─── Locale ───────────────────────────────────────────────────────────────────

export type LocaleCode = string;

/**
 * Map of locale code → display label.
 * @example { en: 'English', vi: 'Tiếng Việt', ja: '日本語' }
 */
export type LocaleMap = Record<LocaleCode, string>;

/** Value shape for translatable fields: locale code → string content. */
export type TranslatableValue = Record<LocaleCode, string>;

/** Locale configuration used by UIProvider and translatable fields. */
export interface UILocaleConfig {
  /** Available locales. e.g. `{ en: 'English', vi: 'Tiếng Việt' }` */
  locales: LocaleMap;
  /** Locale shown by default when a translatable field is first rendered. */
  defaultLocale: LocaleCode;
  /** Locale to fall back to when the active locale has no value. */
  fallbackLocale: LocaleCode;
}

/**
 * `true`  — inherit UIProvider's locale config.
 * `object` — override per-field (merged with provider config).
 */
export type TranslatableConfig = true | Partial<UILocaleConfig>;

// ─── Context ──────────────────────────────────────────────────────────────────

export interface UIContextValue {
  theme: Theme;
  setTheme: (theme: Theme) => void;
  locale: UILocaleConfig | undefined;
  currentLocale: LocaleCode;
  setLocale: (locale: LocaleCode) => void;
  dateFnsLocale: object | undefined;
  timezone: string;
  setTimezone: (tz: string) => void;
}

export const UIContext = createContext<UIContextValue | undefined>(undefined);
