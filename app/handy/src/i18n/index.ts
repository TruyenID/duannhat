import { useCallback, useEffect, useState } from 'react';
import { ja } from './ja';
import { vi } from './vi';
import { saveLocalePreference } from '@/lib/preferences';

export type Locale = 'ja' | 'vi';

const translations = { ja, vi } as const;

let currentLocale: Locale = 'ja';
const subscribers = new Set<() => void>();

export function setLocale(locale: Locale, persist = true) {
  currentLocale = locale;
  subscribers.forEach((fn) => fn());
  if (persist) saveLocalePreference(locale);
}

export function getLocale(): Locale {
  return currentLocale;
}

export function useT() {
  const [, forceUpdate] = useState(0);

  useEffect(() => {
    const notify = () => forceUpdate((n) => n + 1);
    subscribers.add(notify);
    return () => { subscribers.delete(notify); };
  }, []);

  return translations[currentLocale];
}

export function useLocale(): [Locale, (l: Locale) => void] {
  const [locale, setLocaleState] = useState<Locale>(currentLocale);

  const change = useCallback((l: Locale) => {
    setLocaleState(l);
    setLocale(l);
  }, []);

  return [locale, change];
}
