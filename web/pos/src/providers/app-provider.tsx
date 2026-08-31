import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import {
  DEFAULT_LOCALE,
  FALLBACK_LOCALE,
  LOCALE_STORAGE_KEY,
  SUPPORTED_LOCALES,
  getTranslations,
  isLocaleCode,
  type LocaleCode,
} from "@/i18n";

// ---------------------------------------------------------------------------
//  Dev-only missing-translation tracker
// ---------------------------------------------------------------------------

// Module-scope Set so repeated renders don't spam the console — each key
// fires its warning exactly once per page load. Cleared by full reload.
const _warnedKeys = new Set<string>();

function warnMissingTranslationOnce(key: string): void {
  if (_warnedKeys.has(key)) return;
  _warnedKeys.add(key);
  // eslint-disable-next-line no-console
  console.warn(`[i18n] missing key "${key}" — add it to vi/en/ja.json`);
}

// ---------------------------------------------------------------------------
//  Context
// ---------------------------------------------------------------------------

interface AppContextValue {
  locale: LocaleCode;
  setLocale: (locale: LocaleCode) => void;
  locales: typeof SUPPORTED_LOCALES;
  t: (key: string, params?: Record<string, string | number>) => string;
}

const AppContext = createContext<AppContextValue | null>(null);

// ---------------------------------------------------------------------------
//  Provider
// ---------------------------------------------------------------------------

export function AppProvider({ children }: { children: ReactNode }) {
  const [locale, setLocaleState] = useState<LocaleCode>(() => {
    const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
    return isLocaleCode(stored) ? stored : DEFAULT_LOCALE;
  });

  const setLocale = useCallback((next: LocaleCode) => {
    setLocaleState(next);
    localStorage.setItem(LOCALE_STORAGE_KEY, next);
    document.documentElement.lang = next;
  }, []);

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const translations = useMemo(() => getTranslations(locale), [locale]);
  const fallback = useMemo(() => getTranslations(FALLBACK_LOCALE), []);

  const t = useCallback(
    (key: string, params?: Record<string, string | number>): string => {
      // Resolution chain: active locale → fallback locale → the key
      // itself as a last resort. Returning the key string preserves
      // backwards-compat with call sites that rely on a non-empty
      // string return value, but it's also the root of the
      // user-reported `coupon.error.generic` regression — a missing
      // translation looks like a "value" and screen-renders verbatim.
      //
      // The fix is structural rather than just adding the missing key
      // for "generic": surface the resolution failure in dev so future
      // missing keys get caught BEFORE they ship. In production we
      // still return the key (more useful than an empty string for
      // operators reading the screen + helps i18n QA grep for the
      // exact key to add).
      let value = translations[key] ?? fallback[key];
      if (value === undefined) {
        if (import.meta.env.DEV) {
          // Loud in dev so QA + devs spot the gap during local runs.
          // Suppress duplicate noise by tracking a per-key warning
          // bit on a Set keyed at module scope (see below).
          warnMissingTranslationOnce(key);
        }
        value = key;
      }
      if (params) {
        for (const [k, v] of Object.entries(params)) {
          value = value.replaceAll(`{${k}}`, String(v));
        }
      }
      return value;
    },
    [translations, fallback],
  );

  const value = useMemo<AppContextValue>(
    () => ({ locale, setLocale, locales: SUPPORTED_LOCALES, t }),
    [locale, setLocale, t],
  );

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}

// ---------------------------------------------------------------------------
//  Hooks
// ---------------------------------------------------------------------------

function useAppContext(): AppContextValue {
  const ctx = useContext(AppContext);
  if (!ctx) throw new Error("useAppContext must be used within AppProvider");
  return ctx;
}

export function useTranslation() {
  const { t, locale } = useAppContext();
  return { t, locale };
}

/**
 * Like {@link useTranslation}, but survives being rendered with no
 * `AppProvider` above it.
 *
 * For a screen this would be a bug worth throwing on — that is why
 * `useTranslation` still does. But some components are deliberately built to be
 * mountable in isolation: `GapReconcilePanel` takes its own `t` as a prop so it
 * stays a pure function of its inputs, and its test renders it under a bare
 * QueryClientProvider. A shared, drop-in child like the help `?` must not be the
 * thing that revokes that property from every host it is added to.
 *
 * Outside a provider it falls back to {@link getT} + the stored locale — the
 * same localStorage-first path `DialogBoundary`'s fallback uses, and for the
 * same reason. INSIDE a provider it consumes the context like any other reader,
 * so a language switch still re-renders it; reading localStorage unconditionally
 * would not, because a component that consumes no context does not re-render
 * when that context changes.
 */
export function useOptionalTranslation() {
  const ctx = useContext(AppContext);
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
  if (ctx) return { t: ctx.t, locale: ctx.locale };
  return {
    t: getT(),
    locale: isLocaleCode(stored) ? stored : DEFAULT_LOCALE,
  };
}

export function useLocale() {
  const { locale, setLocale, locales, t } = useAppContext();
  return { locale, setLocale, locales, t };
}

// ---------------------------------------------------------------------------
//  Standalone t() — usable outside React components (hooks, utilities)
// ---------------------------------------------------------------------------

/**
 * Returns a translation function based on the current locale in localStorage.
 * Use this in non-component code (hooks, service helpers) where useTranslation
 * is not available.
 */
export function getT(): (key: string, params?: Record<string, string | number>) => string {
  const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
  const locale = isLocaleCode(stored) ? stored : DEFAULT_LOCALE;
  const translations = getTranslations(locale);
  const fallback = getTranslations(FALLBACK_LOCALE);

  return (key: string, params?: Record<string, string | number>): string => {
    let value = translations[key] ?? fallback[key];
    if (value === undefined) {
      if (import.meta.env.DEV) {
        warnMissingTranslationOnce(key);
      }
      value = key;
    }
    if (params) {
      for (const [k, v] of Object.entries(params)) {
        value = value.replaceAll(`{${k}}`, String(v));
      }
    }
    return value;
  };
}
