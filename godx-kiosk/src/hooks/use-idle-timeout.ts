// src/hooks/use-idle-timeout.ts
import { useCallback, useEffect, useState } from "react";
import AsyncStorage from "@react-native-async-storage/async-storage";

const STORAGE_KEY = "kiosk_idle_timeout_seconds";
/** Fallback when the operator hasn't configured a value (was hard-coded). */
export const DEFAULT_IDLE_TIMEOUT_SECONDS = 60;

// Shared module-level store so the IdleTimer and the Settings screen always
// read/write the SAME value — a save in Settings takes effect immediately
// without a reload (mirrors use-terminal-config's pattern).
let cachedSeconds = DEFAULT_IDLE_TIMEOUT_SECONDS;
let hydrated = false;
let hydrating: Promise<void> | null = null;
const listeners = new Set<() => void>();

function emit() {
  for (const listener of listeners) listener();
}

function hydrateOnce(): Promise<void> {
  if (hydrated) return Promise.resolve();
  if (hydrating) return hydrating;
  hydrating = AsyncStorage.getItem(STORAGE_KEY).then((raw) => {
    const n = raw ? parseInt(raw, 10) : NaN;
    if (Number.isFinite(n) && n > 0) {
      cachedSeconds = n;
    }
    hydrated = true;
    hydrating = null;
    emit();
  });
  return hydrating;
}

interface UseIdleTimeoutReturn {
  /** Idle timeout in SECONDS. */
  seconds: number;
  isLoading: boolean;
  /** Persist a new timeout (seconds). Throws "invalid" for non-positive input. */
  save: (seconds: number) => Promise<void>;
}

/**
 * Operator-configurable idle timeout: after this many seconds of no touch on a
 * regular kiosk screen, IdleTimer sends the customer back to /advertise. Set in
 * the kiosk Settings screen.
 */
export function useIdleTimeout(): UseIdleTimeoutReturn {
  const [seconds, setSeconds] = useState(cachedSeconds);
  const [isLoading, setIsLoading] = useState(!hydrated);

  useEffect(() => {
    const sync = () => {
      setSeconds(cachedSeconds);
      setIsLoading(!hydrated);
    };
    listeners.add(sync);
    sync();
    void hydrateOnce();
    return () => {
      listeners.delete(sync);
    };
  }, []);

  const save = useCallback(async (next: number) => {
    if (!Number.isFinite(next) || next <= 0) {
      throw new Error("invalid");
    }
    const rounded = Math.round(next);
    await AsyncStorage.setItem(STORAGE_KEY, String(rounded));
    cachedSeconds = rounded;
    hydrated = true;
    emit();
  }, []);

  return { seconds, isLoading, save };
}
