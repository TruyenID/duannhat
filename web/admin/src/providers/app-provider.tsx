"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import {
  DEFAULT_LOCALE,
  FALLBACK_LOCALE,
  LOCALE_COOKIE,
  SUPPORTED_LOCALES,
  getTranslations,
  isLocaleCode,
  type LocaleCode,
} from "@/i18n";
import { apiFetch, ApiError } from "@/lib/api";
import { setApiLocale } from "@/lib/api-locale";
import { UIProvider, useLocale as useUILocale } from "@godxjp/ui";
import { useQueryClient } from "@tanstack/react-query";

// Query key roots that MUST NOT refetch on locale switch.
// `me` is the auth context (user, brands, shops sidebar) — refetching it on
// every locale change would hammer the auth endpoint and can mask 401 loops.
// Every other root (products, categories, menus, catalog lookups, hq/shop
// layout metadata) may hold translated content, so we refetch them so the
// UI reflects the newly-selected locale's Accept-Language response.
const LOCALE_INVALIDATION_EXCLUDE_ROOTS = new Set(["me"]);

// 1 year — long enough to feel permanent, short enough that abandoned
// devices eventually drop the preference.
const LOCALE_COOKIE_MAX_AGE = 60 * 60 * 24 * 365;

function writeLocaleCookie(locale: LocaleCode): void {
  if (typeof document === "undefined") return;
  document.cookie = `${LOCALE_COOKIE}=${locale}; path=/; max-age=${LOCALE_COOKIE_MAX_AGE}; samesite=lax`;
}

function hasLocaleCookie(): boolean {
  if (typeof document === "undefined") return false;
  return document.cookie.split("; ").some((c) => c.startsWith(`${LOCALE_COOKIE}=`));
}

/**
 * Fire-and-forget API call for persisting user preferences (locale,
 * theme, timezone). Goes through apiFetch so auth/locale headers stay
 * centralized, but opts out of the 401 redirect because bouncing the
 * user to /login because their token expired while they were changing
 * a theme is worse than silently dropping the update — the next real
 * request will trigger the redirect anyway.
 */
function persistPreference(path: string, body: object): void {
  if (typeof window === "undefined") return;
  apiFetch<void>(path, {
    method: "POST",
    body: JSON.stringify(body),
    silent401: true,
  }).catch((err) => {
    // Swallow — preference persistence is best-effort. Log anything that
    // isn't a 401 so silent server errors don't go completely unnoticed.
    if (!(err instanceof ApiError) || err.status !== 401) {
      console.warn("[preference] failed to persist", path, err);
    }
  });
}

// =========================================================================
//  Types
// =========================================================================

type Theme = "light" | "dark" | "system";

interface AppContextValue {
  // Locale
  locale: LocaleCode;
  setLocale: (locale: LocaleCode) => void;
  locales: Record<LocaleCode, string>;
  t: (key: string, params?: Record<string, string | number>) => string;

  // Theme
  theme: Theme;
  setTheme: (theme: Theme) => void;

  // Timezone
  timezone: string;
  setTimezone: (timezone: string) => void;
}

const AppContext = createContext<AppContextValue | undefined>(undefined);

// =========================================================================
//  Storage Keys
// =========================================================================

const STORAGE_THEME = "app_theme";
const STORAGE_LOCALE = "app_locale";
const STORAGE_TIMEZONE = "app_timezone";

// =========================================================================
//  Provider
// =========================================================================

interface AppProviderProps {
  children: ReactNode;
  defaultLocale?: LocaleCode;
  defaultTimezone?: string;
  defaultTheme?: Theme;
}

// Syncs AppProvider's locale into UIProvider's internal state without
// remounting UIProvider. Prevents the theme flash that `key={locale}` caused
// by tearing down TenantThemeProvider on every locale switch.
function UILocaleBridge({ locale }: { locale: LocaleCode }) {
  const { setLocale, currentLocale } = useUILocale();
  const prevRef = useRef(locale);
  useEffect(() => {
    if (prevRef.current !== locale && currentLocale !== locale) {
      prevRef.current = locale;
      setLocale(locale);
    }
  }, [locale, currentLocale, setLocale]);
  return null;
}

export function AppProvider({
  children,
  defaultLocale,
  defaultTimezone,
  defaultTheme,
}: AppProviderProps) {
  const queryClient = useQueryClient();

  // --- Locale ---
  // `defaultLocale` is provided by the root server layout via the
  // app_locale cookie, so SSR and CSR start in the same language and
  // there is no flash of the default locale on first paint. The legacy
  // localStorage value is migrated into the cookie below for users who
  // last set their language before the cookie existed.
  const [locale, setLocaleState] = useState<LocaleCode>(defaultLocale ?? DEFAULT_LOCALE);
  setApiLocale(locale);

  useEffect(() => {
    document.documentElement.lang = locale;

    // One-time migration: copy a pre-existing localStorage locale into the
    // cookie so the next request can be SSR'd in the right language.
    const stored = localStorage.getItem(STORAGE_LOCALE);
    if (stored && stored !== locale && (stored === "ja" || stored === "en" || stored === "vi")) {
      setLocaleState(stored as LocaleCode);
      writeLocaleCookie(stored as LocaleCode);
      document.documentElement.lang = stored;
    } else if (!hasLocaleCookie()) {
      writeLocaleCookie(locale);
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const setLocale = useCallback(
    (newLocale: LocaleCode) => {
      // Synchronize apiFetch before preference persistence or query refetches
      // can run. Production may still carry an older HttpOnly cookie from a
      // previous release, so the current in-memory locale is authoritative.
      setApiLocale(newLocale);
      setLocaleState(newLocale);
      localStorage.setItem(STORAGE_LOCALE, newLocale);
      writeLocaleCookie(newLocale);
      document.documentElement.lang = newLocale;
      persistPreference("/api/v1/locale", { locale: newLocale });

      // Immediately refresh every active API-backed query so translated data
      // changes with the static UI instead of waiting for a reload or focus.
      // Auth (`me`) is excluded as a precaution.
      queryClient.invalidateQueries({
        predicate: (query) => {
          const root = query.queryKey[0];
          return typeof root === "string" && !LOCALE_INVALIDATION_EXCLUDE_ROOTS.has(root);
        },
        refetchType: "active",
      });
    },
    [queryClient]
  );

  // Translation function
  const translations = useMemo(() => getTranslations(locale), [locale]);
  const fallbackTranslations = useMemo(() => getTranslations(FALLBACK_LOCALE), []);

  const t = useCallback(
    (key: string, params?: Record<string, string | number>): string => {
      let value = translations[key] ?? fallbackTranslations[key] ?? key;

      if (params) {
        for (const [param, replacement] of Object.entries(params)) {
          // Function replacement disables `$`-pattern interpretation ($&, $',
          // $$ …) so user-entered values containing `$` aren't corrupted;
          // replaceAll handles a placeholder that appears more than once.
          value = value.replaceAll(`{${param}}`, () => String(replacement));
        }
      }

      return value;
    },
    [translations, fallbackTranslations]
  );

  // --- Theme ---
  const [theme, setThemeState] = useState<Theme>(defaultTheme ?? "system");

  useEffect(() => {
    const stored = localStorage.getItem(STORAGE_THEME) as Theme | null;
    if (stored && stored !== theme) {
      setThemeState(stored);
    }
    applyTheme(stored ?? theme);
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const setTheme = useCallback((newTheme: Theme) => {
    setThemeState(newTheme);
    localStorage.setItem(STORAGE_THEME, newTheme);
    applyTheme(newTheme);
  }, []);

  // --- Timezone ---
  const [timezone, setTimezoneState] = useState<string>(defaultTimezone ?? "UTC");

  useEffect(() => {
    const stored = localStorage.getItem(STORAGE_TIMEZONE);
    const browserTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
    const resolved = stored ?? browserTz;
    if (resolved !== timezone) {
      setTimezoneState(resolved);
    }
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const setTimezone = useCallback((newTimezone: string) => {
    setTimezoneState(newTimezone);
    localStorage.setItem(STORAGE_TIMEZONE, newTimezone);
    persistPreference("/api/v1/timezone", { timezone: newTimezone });
  }, []);

  // --- Context Value ---
  const value = useMemo<AppContextValue>(
    () => ({
      locale,
      setLocale,
      locales: SUPPORTED_LOCALES,
      t,
      theme,
      setTheme,
      timezone,
      setTimezone,
    }),
    [locale, setLocale, t, theme, setTheme, timezone, setTimezone]
  );

  // Mount the design-system's UIProvider so `<Input translatable />`,
  // `<Textarea translatable />`, and `<TranslatableField>` resolve their
  // locale config without per-field props.
  //
  // We intentionally do NOT use `key={locale}` here. A key-based remount
  // would unmount every descendant on locale change — including
  // TenantThemeProvider — causing its cleanup to strip tenant CSS vars from
  // document.body and producing a theme flash. Instead, UILocaleBridge
  // (rendered as a child) calls UIProvider's internal setLocale to push
  // locale changes in without remounting the tree.
  return (
    <AppContext value={value}>
      <UIProvider
        locales={SUPPORTED_LOCALES}
        defaultLocale={locale}
        fallbackLocale={FALLBACK_LOCALE}
        onLocaleChange={(loc) => {
          // Fired when locale changes from inside UIProvider (e.g. a
          // TranslatableField locale switcher). Narrow to our LocaleCode
          // union and sync back to AppProvider.
          if (isLocaleCode(loc)) setLocale(loc);
        }}
      >
        <UILocaleBridge locale={locale} />
        {children}
      </UIProvider>
    </AppContext>
  );
}

// =========================================================================
//  Hooks
// =========================================================================

function useAppContext(): AppContextValue {
  const context = useContext(AppContext);
  if (!context) {
    throw new Error("useAppContext must be used within <AppProvider>");
  }
  return context;
}

export function useLocale() {
  const { locale, setLocale, locales, t } = useAppContext();
  return { locale, setLocale, locales, t };
}

export function useTranslation() {
  const { t, locale } = useAppContext();
  return { t, locale };
}

export function useTheme() {
  const { theme, setTheme } = useAppContext();
  return { theme, setTheme };
}

export function useTimezone() {
  const { timezone, setTimezone } = useAppContext();
  return { timezone, setTimezone };
}

// =========================================================================
//  Helpers
// =========================================================================

function applyTheme(theme: Theme) {
  const root = document.documentElement;
  root.classList.remove("dark");

  if (theme === "dark") {
    root.classList.add("dark");
  } else if (theme === "system") {
    if (window.matchMedia("(prefers-color-scheme: dark)").matches) {
      root.classList.add("dark");
    }
  }
}
