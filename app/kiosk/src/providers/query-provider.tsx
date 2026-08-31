/**
 * TanStack React Query provider with React Native adaptations.
 *
 * - focusManager wired to AppState (refetch on foreground)
 * - Retry disabled for 401/403 (device token expired)
 * - 15s staleTime for table status polling
 */

import { useEffect, type ReactNode } from "react";
import { AppState, type AppStateStatus } from "react-native";
import {
  QueryClient,
  QueryClientProvider,
  focusManager,
} from "@tanstack/react-query";
import { ApiError } from "../lib/api";

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 15_000,
      refetchOnWindowFocus: true, // handled by focusManager below
      retry: (failureCount, error) => {
        if (error instanceof ApiError && (error.isAuthError || error.isForbidden)) {
          return false;
        }
        return failureCount < 1;
      },
    },
    mutations: {
      retry: false,
    },
  },
});

export function QueryProvider({ children }: { children: ReactNode }) {
  // Wire TanStack Query's focusManager to React Native's AppState.
  // When app comes back to foreground, all stale queries refetch.
  useEffect(() => {
    const onAppStateChange = (status: AppStateStatus) => {
      focusManager.setFocused(status === "active");
    };

    const sub = AppState.addEventListener("change", onAppStateChange);
    return () => sub.remove();
  }, []);

  return (
    <QueryClientProvider client={queryClient}>
      {children}
    </QueryClientProvider>
  );
}
