import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from "react";
import AsyncStorage from "@react-native-async-storage/async-storage";
import {
  DEFAULT_LOCALE,
  FALLBACK_LOCALE,
  STORAGE_LOCALE,
  SUPPORTED_LOCALES,
  getTranslations,
  isLocaleCode,
  type LocaleCode,
} from "../i18n";

type Theme = "light" | "dark" | "system";

interface AppContextValue {
  locale: LocaleCode;
  setLocale: (locale: LocaleCode) => void;
  locales: Record<LocaleCode, string>;
  t: (key: string, params?: Record<string, string | number>) => string;
  theme: Theme;
  setTheme: (theme: Theme) => void;
}

const AppContext = createContext<AppContextValue | undefined>(undefined);

const STORAGE_THEME = "tms_theme";

interface AppProviderProps {
  children: ReactNode;
}

export function AppProvider({ children }: AppProviderProps) {
  const [locale, setLocaleState] = useState<LocaleCode>(DEFAULT_LOCALE);
  const [theme, setThemeState] = useState<Theme>("system");

  // Restore persisted preferences on mount
  useEffect(() => {
    (async () => {
      const [storedLocale, storedTheme] = await Promise.all([
        AsyncStorage.getItem(STORAGE_LOCALE),
        AsyncStorage.getItem(STORAGE_THEME),
      ]);
      if (storedLocale && isLocaleCode(storedLocale)) {
        setLocaleState(storedLocale);
      }
      if (storedTheme === "light" || storedTheme === "dark" || storedTheme === "system") {
        setThemeState(storedTheme);
      }
    })();
  }, []);

  const setLocale = useCallback((newLocale: LocaleCode) => {
    setLocaleState(newLocale);
    AsyncStorage.setItem(STORAGE_LOCALE, newLocale);
  }, []);

  const setTheme = useCallback((newTheme: Theme) => {
    setThemeState(newTheme);
    AsyncStorage.setItem(STORAGE_THEME, newTheme);
  }, []);

  // Translation function — mirrors frontend's t()
  const translations = useMemo(() => getTranslations(locale), [locale]);
  const fallbackTranslations = useMemo(() => getTranslations(FALLBACK_LOCALE), []);

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
    [translations, fallbackTranslations],
  );

  const value = useMemo<AppContextValue>(
    () => ({ locale, setLocale, locales: SUPPORTED_LOCALES, t, theme, setTheme }),
    [locale, setLocale, t, theme, setTheme],
  );

  return <AppContext.Provider value={value}>{children}</AppContext.Provider>;
}

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
