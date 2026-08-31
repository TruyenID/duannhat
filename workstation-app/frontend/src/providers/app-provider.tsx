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
  SUPPORTED_LOCALES,
  getTranslations,
  isLocaleCode,
  type LocaleCode,
} from "../i18n";
import { UIProvider } from "@godxjp/ui";

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

export function AppProvider({
  children,
  defaultLocale,
  defaultTimezone,
  defaultTheme,
}: AppProviderProps) {
  // --- Locale ---
  const [locale, setLocaleState] = useState<LocaleCode>(() => {
    const stored = localStorage.getItem(STORAGE_LOCALE);
    if (stored && isLocaleCode(stored)) return stored;
    return defaultLocale ?? DEFAULT_LOCALE;
  });

  useEffect(() => {
    document.documentElement.lang = locale;
  }, [locale]);

  const setLocale = useCallback((newLocale: LocaleCode) => {
    setLocaleState(newLocale);
    localStorage.setItem(STORAGE_LOCALE, newLocale);
    document.documentElement.lang = newLocale;
  }, []);

  // Translation function
  const translations = useMemo(() => getTranslations(locale), [locale]);
  const fallbackTranslations = useMemo(
    () => getTranslations(FALLBACK_LOCALE),
    []
  );

  const t = useCallback(
    (key: string, params?: Record<string, string | number>): string => {
      let value = translations[key] ?? fallbackTranslations[key] ?? key;

      if (params) {
        for (const [param, replacement] of Object.entries(params)) {
          value = value.replace(`{${param}}`, String(replacement));
        }
      }

      return value;
    },
    [translations, fallbackTranslations]
  );

  // --- Theme ---
  const [theme, setThemeState] = useState<Theme>(() => {
    const stored = localStorage.getItem(STORAGE_THEME) as Theme | null;
    return stored ?? defaultTheme ?? "system";
  });

  useEffect(() => {
    applyTheme(theme);
  }, [theme]);

  const setTheme = useCallback((newTheme: Theme) => {
    setThemeState(newTheme);
    localStorage.setItem(STORAGE_THEME, newTheme);
    applyTheme(newTheme);
  }, []);

  // --- Timezone ---
  const [timezone, setTimezoneState] = useState<string>(() => {
    const stored = localStorage.getItem(STORAGE_TIMEZONE);
    return stored ?? Intl.DateTimeFormat().resolvedOptions().timeZone ?? defaultTimezone ?? "UTC";
  });

  const setTimezone = useCallback((newTimezone: string) => {
    setTimezoneState(newTimezone);
    localStorage.setItem(STORAGE_TIMEZONE, newTimezone);
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

  return (
    <AppContext value={value}>
      <UIProvider
        key={locale}
        locales={SUPPORTED_LOCALES}
        defaultLocale={locale}
        fallbackLocale={FALLBACK_LOCALE}
        onLocaleChange={(loc) => {
          if (isLocaleCode(loc)) setLocale(loc);
        }}
      >
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
