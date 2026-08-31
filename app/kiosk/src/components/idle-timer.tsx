// src/components/idle-timer.tsx
import { useEffect, useRef, useCallback } from 'react';
import { View } from 'react-native';
import { useRouter, usePathname } from 'expo-router';
import { useIdleTimeout } from '../hooks/use-idle-timeout';

const PAYMENT_TIMEOUT_MS = 300_000;   // 5 minutes — deliberately NOT configurable

// `defaultMs` is the operator-configured idle timeout (kiosk Settings). Payment
// screens keep a fixed longer window so a customer mid-transaction isn't kicked.
function getTimeoutForPath(pathname: string, defaultMs: number): number | null {
  if (pathname === '/advertise' || pathname === '/login') return null;
  if (
    pathname.startsWith('/payment') ||
    pathname === '/success' ||
    pathname.startsWith('/split') ||
    pathname.startsWith('/custom')
  ) return PAYMENT_TIMEOUT_MS;
  return defaultMs;
}

interface IdleTimerProps {
  children: React.ReactNode;
}

export function IdleTimer({ children }: IdleTimerProps) {
  const router = useRouter();
  const pathname = usePathname();
  const { seconds } = useIdleTimeout();
  const defaultMs = seconds * 1000;
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  const resetTimer = useCallback(() => {
    if (timerRef.current) clearTimeout(timerRef.current);
    const timeout = getTimeoutForPath(pathname, defaultMs);
    if (timeout === null) return;
    timerRef.current = setTimeout(() => {
      router.replace('/advertise');
    }, timeout);
  }, [router, pathname, defaultMs]);

  useEffect(() => {
    const timeout = getTimeoutForPath(pathname, defaultMs);
    if (timeout === null) {
      if (timerRef.current) clearTimeout(timerRef.current);
      return;
    }
    resetTimer();
    return () => {
      if (timerRef.current) clearTimeout(timerRef.current);
    };
  }, [pathname, resetTimer, defaultMs]);

  return (
    <View
      className="flex-1"
      onStartShouldSetResponder={() => {
        resetTimer();
        return false;
      }}
    >
      {children}
    </View>
  );
}
