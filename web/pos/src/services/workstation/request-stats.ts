/**
 * Per-session request counters — how many API calls actually routed
 * through workstation (LAN) vs cloud since the page loaded. Mounted into
 * the ConnectionStatusBadge dropdown so cashiers (and we) can verify at
 * a glance that LAN mode is doing what it claims to.
 *
 * Counts every settled request (success, 4xx, 5xx). Network errors that
 * trigger the auto-mode workstation→cloud fallback are recorded twice:
 * once as a failed LAN attempt and once as the cloud retry — both data
 * points are useful for diagnosing intermittent LAN issues.
 *
 * Reset is a manual action (dropdown → "Reset counters"), so the numbers
 * accumulate across a whole shift unless the cashier explicitly clears.
 *
 * Implementation: plain module state + tiny subscriber set. No external
 * deps; the React side wraps with useSyncExternalStore in the hook below.
 */

import { useSyncExternalStore } from "react";

export interface RequestStats {
  lan: number;
  cloud: number;
  lanFailures: number;
  cloudFailures: number;
  lanLastAt: number | null;
  cloudLastAt: number | null;
  /** Bumped on every change so React can pick up identity-level updates. */
  version: number;
}

let stats: RequestStats = {
  lan: 0,
  cloud: 0,
  lanFailures: 0,
  cloudFailures: 0,
  lanLastAt: null,
  cloudLastAt: null,
  version: 0,
};

const listeners = new Set<() => void>();

function emit() {
  for (const fn of listeners) fn();
}

export function recordRequest(
  via: "workstation" | "cloud",
  outcome: "ok" | "fail",
): void {
  const now = Date.now();
  if (via === "workstation") {
    stats = {
      ...stats,
      lan: stats.lan + 1,
      lanFailures: outcome === "fail" ? stats.lanFailures + 1 : stats.lanFailures,
      lanLastAt: now,
      version: stats.version + 1,
    };
  } else {
    stats = {
      ...stats,
      cloud: stats.cloud + 1,
      cloudFailures: outcome === "fail" ? stats.cloudFailures + 1 : stats.cloudFailures,
      cloudLastAt: now,
      version: stats.version + 1,
    };
  }
  emit();
}

export function resetRequestStats(): void {
  stats = {
    lan: 0,
    cloud: 0,
    lanFailures: 0,
    cloudFailures: 0,
    lanLastAt: null,
    cloudLastAt: null,
    version: stats.version + 1,
  };
  emit();
}

export function getRequestStats(): RequestStats {
  return stats;
}

function subscribe(fn: () => void): () => void {
  listeners.add(fn);
  return () => {
    listeners.delete(fn);
  };
}

/** React hook: subscribe to live updates of the counters. */
export function useRequestStats(): RequestStats {
  return useSyncExternalStore(subscribe, getRequestStats, getRequestStats);
}
