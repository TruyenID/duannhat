import { useQuery } from "@tanstack/react-query";
import {
  getOrdersWithMeta,
  type KdsOrdersQueryData,
} from "@/services/kds/orders";
import { useAuth } from "@/providers/use-auth";
import { useRealtime } from "@/providers/use-realtime";
import { saveOrdersSnapshot } from "@/lib/idb";

export function useOrders() {
  const { state } = useAuth();
  const { isConnected } = useRealtime();

  return useQuery<KdsOrdersQueryData>({
    queryKey: ["kds", "orders"],
    queryFn: async () => {
      const result = await getOrdersWithMeta();
      // Best-effort offline cache for cold boots. Persist ONLY the orders
      // array — never meta. DashboardPage's fallback counts (`countLate` etc.)
      // depend on this invariant; if a future PR adds meta here, the offline
      // banner stops reflecting the true late-count.
      void saveOrdersSnapshot({
        fetched_at: new Date().toISOString(),
        orders: result.orders,
      });
      return result;
    },
    enabled: state === "paired",
    staleTime: 5_000,
    // Disable polling when WS is live — invalidation comes from RealtimeProvider.
    // Fall back to 15s polling when WS is down (no connection / cloud fallback).
    refetchInterval: isConnected ? false : 15_000,
  });
}
