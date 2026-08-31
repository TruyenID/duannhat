import { createContext, useContext, useState, useEffect, createElement } from "react";
import type { ReactNode, FC } from "react";
import ja from "./ja.json";
import en from "./en.json";
import vi from "./vi.json";

export type LocaleCode = "ja" | "en" | "vi";

export const SUPPORTED_LOCALES: Record<LocaleCode, string> = {
  ja: "日本語",
  en: "English",
  vi: "Tiếng Việt",
};

export const DEFAULT_LOCALE: LocaleCode = "ja";
export const FALLBACK_LOCALE: LocaleCode = "en";

export const LOCALE_STORAGE_KEY = "kds_locale";

export function isLocaleCode(value: unknown): value is LocaleCode {
  return value === "ja" || value === "en" || value === "vi";
}

type TranslationDict = Record<string, string>;

const dictionaries: Record<LocaleCode, TranslationDict> = { ja, en, vi };

export function getTranslations(locale: LocaleCode): TranslationDict {
  return dictionaries[locale] ?? dictionaries[FALLBACK_LOCALE];
}

function interpolate(text: string, vars?: Record<string, string | number>): string {
  if (!vars) return text;
  return text.replace(/{{(\w+)}}/g, (_, key) => String(vars[key] ?? key));
}

export function translate(
  locale: LocaleCode,
  key: string,
  vars?: Record<string, string | number>,
): string {
  const translations = getTranslations(locale);
  const fallbackTranslations = getTranslations(FALLBACK_LOCALE);
  const text = translations[key] ?? fallbackTranslations[key] ?? key;

  return interpolate(text, vars);
}

interface I18nContextType {
  locale: LocaleCode;
  setLocale: (locale: LocaleCode) => void;
  t: (key: string, vars?: Record<string, string | number>) => string;
}

const I18nContext = createContext<I18nContextType | undefined>(undefined);

interface I18nProviderProps {
  children: ReactNode;
}

const I18nProvider: FC<I18nProviderProps> = ({ children }) => {
  const [locale, setLocaleState] = useState<LocaleCode>(() => {
    if (typeof window === "undefined") return DEFAULT_LOCALE;
    const stored = localStorage.getItem(LOCALE_STORAGE_KEY);
    return isLocaleCode(stored) ? stored : DEFAULT_LOCALE;
  });

  useEffect(() => {
    localStorage.setItem(LOCALE_STORAGE_KEY, locale);
    document.documentElement.lang = locale;
  }, [locale]);

  const setLocale = (newLocale: LocaleCode) => {
    setLocaleState(newLocale);
  };

  const t = (key: string, vars?: Record<string, string | number>) => {
    return translate(locale, key, vars);
  };

  return createElement(
    I18nContext.Provider,
    { value: { locale, setLocale, t } },
    children
  );
};

export { I18nProvider };

export function useTranslation() {
  const context = useContext(I18nContext);
  if (context === undefined) {
    throw new Error("useTranslation must be used within an I18nProvider");
  }
  return context;
}
