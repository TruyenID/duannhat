import { useState, useEffect, useCallback, type ReactNode } from 'react';
import { UIContext } from './ui-context';
import type { Theme, LocaleMap, LocaleCode, UILocaleConfig } from './ui-context';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function loadSavedTheme(): Theme {
  if (typeof window === 'undefined') return 'system';
  const saved = localStorage.getItem('omnify_theme');
  if (saved === 'light' || saved === 'dark' || saved === 'system') return saved;
  return 'system';
}

function applyTheme(theme: Theme) {
  const root = document.documentElement;
  if (theme === 'system') {
    root.classList.toggle('dark', window.matchMedia('(prefers-color-scheme: dark)').matches);
  } else {
    root.classList.toggle('dark', theme === 'dark');
  }
}

// ─── UIProvider ───────────────────────────────────────────────────────────────

export interface UIProviderProps {
  children: ReactNode;
  /**
   * Initial theme. Defaults to user's saved localStorage value or `'system'`.
   */
  defaultTheme?: Theme;
  /**
   * Available locales for translatable fields.
   * @example { en: 'English', vi: 'Tiếng Việt', ja: '日本語' }
   */
  locales?: LocaleMap;
  /**
   * Locale shown first in translatable fields.
   * Defaults to the first key in `locales`.
   */
  defaultLocale?: LocaleCode;
  /**
   * Locale used when a field has no value for the active locale.
   * Defaults to `defaultLocale`.
   */
  fallbackLocale?: LocaleCode;
  /**
   * date-fns `Locale` object used by date components (DatePicker, CalendarMini, etc.).
   * Typed as `object` to avoid importing date-fns as a direct dependency.
   *
   * @example
   * ```tsx
   * import { ja } from 'date-fns/locale';
   * <UIProvider dateFnsLocale={ja}>{children}</UIProvider>
   * ```
   */
  dateFnsLocale?: object;
  /**
   * Callback fired when the active locale changes via `setLocale`.
   * Use this to sync with i18n libraries, localStorage, etc.
   */
  onLocaleChange?: (locale: LocaleCode) => void;
  /**
   * IANA timezone string (e.g. `'Asia/Tokyo'`).
   * Defaults to the browser's local timezone.
   */
  timezone?: string;
  /**
   * Callback fired when the timezone changes via `setTimezone`.
   * Use this to sync with backend, localStorage, etc.
   */
  onTimezoneChange?: (timezone: string) => void;
}

/**
 * Root provider for @omnifyjp/ui — handles dark mode and translatable field config.
 *
 * @example
 * ```tsx
 * <UIProvider
 *   locales={{ en: 'English', vi: 'Tiếng Việt', ja: '日本語' }}
 *   defaultLocale="en"
 *   fallbackLocale="en"
 * >
 *   {children}
 * </UIProvider>
 * ```
 */
export function UIProvider({
  children,
  defaultTheme,
  locales,
  defaultLocale,
  fallbackLocale,
  dateFnsLocale,
  onLocaleChange,
  timezone: timezoneProp,
  onTimezoneChange,
}: UIProviderProps) {
  const [theme, setThemeState] = useState<Theme>(() => defaultTheme ?? loadSavedTheme());

  const setTheme = useCallback((t: Theme) => setThemeState(t), []);

  useEffect(() => {
    localStorage.setItem('omnify_theme', theme);
    applyTheme(theme);
  }, [theme]);

  useEffect(() => {
    if (theme !== 'system') return;
    const mq = window.matchMedia('(prefers-color-scheme: dark)');
    const handler = () => applyTheme('system');
    mq.addEventListener('change', handler);
    return () => mq.removeEventListener('change', handler);
  }, [theme]);

  const firstLocale = locales ? Object.keys(locales)[0] : undefined;
  const resolvedDefaultLocale = defaultLocale ?? firstLocale ?? '';
  const locale: UILocaleConfig | undefined =
    locales && firstLocale
      ? {
          locales,
          defaultLocale: resolvedDefaultLocale,
          fallbackLocale: fallbackLocale ?? resolvedDefaultLocale,
        }
      : undefined;

  const [currentLocale, setCurrentLocale] = useState<LocaleCode>(
    () => resolvedDefaultLocale,
  );

  const setLocale = useCallback(
    (loc: LocaleCode) => {
      setCurrentLocale(loc);
      onLocaleChange?.(loc);
    },
    [onLocaleChange],
  );

  // Auto-set <html lang> for accessibility/SEO
  useEffect(() => {
    if (currentLocale) {
      document.documentElement.lang = currentLocale;
    }
  }, [currentLocale]);

  // ── Timezone ──
  const [timezone, setTimezoneState] = useState<string>(
    () => timezoneProp ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
  );

  const setTimezone = useCallback(
    (tz: string) => {
      setTimezoneState(tz);
      onTimezoneChange?.(tz);
    },
    [onTimezoneChange],
  );

  // Sync when prop changes externally (e.g. Inertia page props update)
  useEffect(() => {
    if (timezoneProp !== undefined) {
      setTimezoneState(timezoneProp);
    }
  }, [timezoneProp]);

  return (
    <UIContext.Provider value={{ theme, setTheme, locale, currentLocale, setLocale, dateFnsLocale, timezone, setTimezone }}>
      {children}
    </UIContext.Provider>
  );
}
