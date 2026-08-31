import { useCallback, useEffect, useState } from 'react';
import { useColorScheme as useRNColorScheme } from 'react-native';
import { saveThemePreference } from '@/lib/preferences';

export type ColorSchemeOverride = 'light' | 'dark' | 'system';

let override: ColorSchemeOverride = 'light';
const subscribers = new Set<() => void>();

export function setColorSchemeOverride(value: ColorSchemeOverride, persist = true) {
  override = value;
  subscribers.forEach((fn) => fn());
  if (persist) saveThemePreference(value);
}

export function getColorSchemeOverride(): ColorSchemeOverride {
  return override;
}

/** Returns the effective color scheme ('light' | 'dark'), respecting manual override. */
export function useColorScheme(): 'light' | 'dark' {
  const systemScheme: 'light' | 'dark' = useRNColorScheme() === 'dark' ? 'dark' : 'light';
  const [, forceUpdate] = useState(0);

  useEffect(() => {
    const notify = () => forceUpdate((n) => n + 1);
    subscribers.add(notify);
    return () => { subscribers.delete(notify); };
  }, []);

  if (override === 'system') return systemScheme;
  return override;
}

/** Returns [currentOverride, setter] for the settings UI. */
export function useColorSchemeOverride(): [ColorSchemeOverride, (v: ColorSchemeOverride) => void] {
  const [value, setValue] = useState<ColorSchemeOverride>(override);

  const set = useCallback((v: ColorSchemeOverride) => {
    setValue(v);
    setColorSchemeOverride(v);
  }, []);

  useEffect(() => {
    const notify = () => setValue(override);
    subscribers.add(notify);
    return () => { subscribers.delete(notify); };
  }, []);

  return [value, set];
}
