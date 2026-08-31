import { useEffect, type ReactNode } from "react";

// Wake Lock API types (TypeScript DOM lib may not include them yet)
interface WakeLockSentinel {
  release: () => Promise<void>;
}

interface WakeLockAPI {
  request: (type: "screen") => Promise<WakeLockSentinel>;
}

/**
 * Acquires a Screen Wake Lock so the kitchen tablet stays awake while
 * the dashboard is open. Re-acquires on visibility change (browsers
 * release wake lock when tab loses focus).
 *
 * Support matrix:
 *   - iPad Safari 16.4+ ✓
 *   - Chrome Android 84+ ✓
 *   - Older Safari → no-op (operator must disable OS auto-lock manually)
 *
 * Failures are silent — log to console.warn for ops visibility.
 */
export function WakeLockProvider({ children }: { children: ReactNode }) {
  useEffect(() => {
    let sentinel: WakeLockSentinel | null = null;

    async function acquire() {
      const wakeLock = (navigator as unknown as { wakeLock?: WakeLockAPI }).wakeLock;
      if (!wakeLock) return; // not supported
      try {
        sentinel = await wakeLock.request("screen");
      } catch (err) {
        console.warn("[WakeLockProvider] failed to acquire wake lock", err);
      }
    }

    void acquire();

    const onVisibility = () => {
      if (document.visibilityState === "visible") void acquire();
    };
    document.addEventListener("visibilitychange", onVisibility);

    return () => {
      document.removeEventListener("visibilitychange", onVisibility);
      void sentinel?.release().catch(() => {});
      sentinel = null;
    };
  }, []);

  return <>{children}</>;
}
