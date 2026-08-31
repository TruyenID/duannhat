export const locales = ['ja', 'vi', 'en'] as const;
export type Locale = (typeof locales)[number];

export const defaultLocale: Locale = 'ja';

export const localeNames: Record<Locale, string> = {
  ja: '日本語',
  vi: 'Tiếng Việt',
  en: 'English',
};

/** Flag artwork lives in `public/flags/{locale}.svg` (no emoji — Windows/Android
 *  don't render regional-indicator pairs as flags). */
export const localeFlags: Record<Locale, string> = {
  ja: '/flags/ja.svg',
  vi: '/flags/vi.svg',
  en: '/flags/en.svg',
};
