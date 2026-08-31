import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";

export function QueryProvider({ children }: { children: ReactNode }) {
  const [client] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30_000,           // 30s default (kiosk anchor)
            gcTime: 5 * 60_000,
            refetchOnWindowFocus: false, // tablet always mounted, no tab focus
            refetchOnReconnect: true,    // critical on network recovery
            retry: 2,
          },
          mutations: {
            retry: 0,                    // bump should not auto-retry; user clicks again
          },
        },
      })
  );
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
