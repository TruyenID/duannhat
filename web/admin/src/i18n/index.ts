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

/**
 * Cookie name used to persist the user's locale across SSR and CSR.
 *
 * The root layout reads this cookie on the server so the very first HTML
 * payload is rendered in the correct language — without it, every page would
 * flash the default `ja` for one paint and then re-render in the user's
 * actual language as soon as `useEffect` got round to reading localStorage.
 */
export const LOCALE_COOKIE = "app_locale";

/**
 * Type guard so callers can safely narrow an arbitrary cookie/string value
 * to a known LocaleCode without sprinkling `as` casts everywhere.
 */
export function isLocaleCode(value: unknown): value is LocaleCode {
  return value === "ja" || value === "en" || value === "vi";
}

type TranslationDict = Record<string, string>;

const dictionaries: Record<LocaleCode, TranslationDict> = { ja, en, vi };

export function getTranslations(locale: LocaleCode): TranslationDict {
  return dictionaries[locale] ?? dictionaries[FALLBACK_LOCALE];
}
