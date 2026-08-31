"use client";

import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";
import { ApiError } from "@/lib/api";
import { ReactQueryDevtools } from "@tanstack/react-query-devtools";
export function QueryProvider({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 0,
            gcTime: 0,
            refetchOnWindowFocus: false,
            retry: (failureCount, error) => {
              // Never retry auth errors, nor rate-limit (429) responses.
              // Retrying a 429 only burns more of the per-user budget and
              // extends the lockout — on throttled endpoints (e.g. the trace
              // tool, capped per minute) a retry storm is self-defeating and
              // turns one slow request into a cascade of "failed to load".
              if (
                error instanceof ApiError &&
                (error.status === 401 || error.status === 403 || error.status === 429)
              ) {
                return false;
              }
              return failureCount < 1;
            },
          },
          mutations: {
            retry: false,
          },
        },
      })
  );

  return (
    <QueryClientProvider client={queryClient}>
      {children}
      {/* <ReactQueryDevtools initialIsOpen={false} /> */}
    </QueryClientProvider>
  );
}
