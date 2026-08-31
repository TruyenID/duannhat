let activeLocale: string | null = null;

/** Keep API requests aligned with the locale currently rendered by AppProvider. */
export function setApiLocale(locale: string): void {
  activeLocale = locale;
}

export function getApiLocale(): string | null {
  return activeLocale;
}
