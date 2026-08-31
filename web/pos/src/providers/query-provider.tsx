import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { lazy, Suspense, useEffect, useState, type ReactNode } from "react";
import {
  retryDelay,
  shouldRetryMutation,
  shouldRetryQuery,
} from "./query-retry";
import {
  hydrateQueryCache,
  startQueryCachePersistence,
} from "@/lib/offline-cache";
import { installNetworkListeners } from "@/lib/network-status";

// Devtools must not ship to production. Static `import` would bundle the
// panel (and the cache it exposes) into every customer-facing build —
// pos-web handles cash payments, so leaking query state to the page is
// a real security smell. Gate behind `import.meta.env.DEV` and lazy-load
// so the production bundle never references the module.
const ReactQueryDevtools = import.meta.env.DEV
  ? lazy(() =>
      import("@tanstack/react-query-devtools").then((m) => ({
        default: m.ReactQueryDevtools,
      })),
    )
  : null;

export function QueryProvider({ children }: { children: ReactNode }) {
  const [queryClient] = useState(
    () =>
      new QueryClient({
        defaultOptions: {
          queries: {
            staleTime: 30 * 1000,
            refetchOnWindowFocus: false,
            retry: shouldRetryQuery,
            retryDelay,
          },
          mutations: {
            retry: shouldRetryMutation,
          },
        },
      }),
  );

  // #1501 — cache đọc offline (idb) + tín hiệu online/offline của trình duyệt.
  //
  // Đăng ký GHI trước rồi mới hydrate: ngược lại thì mọi query fetch xong
  // trong lúc IndexedDB còn đang mở sẽ không được lưu, tức đúng phiên đầu tiên
  // — phiên duy nhất chắc chắn có cache rỗng — lại chẳng ghi được gì.
  //
  // Chỉ ĐỌC được cache; ranh giới tiền nằm ở `offline-cache-policy.ts`.
  useEffect(() => {
    const stopPersisting = startQueryCachePersistence(queryClient);
    const removeListeners = installNetworkListeners();
    void hydrateQueryCache(queryClient);
    return () => {
      stopPersisting();
      removeListeners();
    };
  }, [queryClient]);

  return (
    <QueryClientProvider client={queryClient}>
      {children}
      {ReactQueryDevtools ? (
        <Suspense fallback={null}>
          <ReactQueryDevtools initialIsOpen={false} />
        </Suspense>
      ) : null}
    </QueryClientProvider>
  );
}
