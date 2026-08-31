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

export const LOCALE_STORAGE_KEY = "pos_locale";

export function isLocaleCode(value: unknown): value is LocaleCode {
  return value === "ja" || value === "en" || value === "vi";
}

type TranslationDict = Record<string, string>;

const dictionaries: Record<LocaleCode, TranslationDict> = { ja, en, vi };

export function getTranslations(locale: LocaleCode): TranslationDict {
  return dictionaries[locale] ?? dictionaries[FALLBACK_LOCALE];
}
