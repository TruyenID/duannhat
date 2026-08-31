"use client";

/**
 * RuntimeConfigProvider — carries the handful of settings that must follow the
 * DEPLOYMENT rather than the build.
 *
 * `NEXT_PUBLIC_*` variables are inlined into the bundle at build time, so an
 * environment whose hostnames move (staging tunnels, a domain migration) can
 * only pick up a new value by rebuilding. The root layout is already dynamic
 * (it awaits `cookies()`), so it reads these from `process.env` per request and
 * hands them down through this context — changing a domain is a service
 * restart, never a rebuild.
 *
 * Server and client render from the SAME object, so nothing here can produce a
 * hydration mismatch (reading `window`/`document` in the consumer would).
 */

import { createContext, useContext } from "react";

export interface RuntimeConfig {
  /**
   * Origin of the customer-facing web app, e.g. `https://menu.example.jp`.
   * Empty string when unset — consumers fall back on their own.
   */
  customerWebUrl: string;
}

const EMPTY_RUNTIME_CONFIG: RuntimeConfig = { customerWebUrl: "" };

const RuntimeConfigContext = createContext<RuntimeConfig>(EMPTY_RUNTIME_CONFIG);

export interface RuntimeConfigProviderProps {
  value: RuntimeConfig;
  children: React.ReactNode;
}

export function RuntimeConfigProvider({ value, children }: RuntimeConfigProviderProps) {
  return (
    <RuntimeConfigContext.Provider value={value}>{children}</RuntimeConfigContext.Provider>
  );
}

export function useRuntimeConfig(): RuntimeConfig {
  return useContext(RuntimeConfigContext);
}
