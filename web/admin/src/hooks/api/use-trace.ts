/**
 * Trace Hooks — React-Query wrappers around trace-service.
 *
 * Backend rate-limits these endpoints at 30/min. Hooks set staleTime to 5
 * minutes so the user investigating a recall can navigate the tree without
 * triggering refetches; let them invalidate manually via a refresh button if
 * they need fresh data.
 */

import { useQuery } from "@tanstack/react-query";

import { traceService, type TraceDirection } from "@/services/trace-service";

import { traceKeys } from "./query-keys";

const FIVE_MINUTES = 5 * 60 * 1000;

export function useTraceLot(
  brandSlug: string,
  lotId: string | undefined,
  options: { direction?: TraceDirection; maxDepth?: number } = {}
) {
  const direction = options.direction ?? "both";
  const maxDepth = options.maxDepth ?? 10;

  return useQuery({
    queryKey: lotId
      ? traceKeys.lot(brandSlug, lotId, direction, maxDepth)
      : ["trace", "lot", "noop"],
    queryFn: () => traceService.lot(brandSlug, lotId!, { direction, maxDepth }),
    enabled: !!brandSlug && !!lotId,
    staleTime: FIVE_MINUTES,
  });
}

export function useTraceCustomerOrder(
  brandSlug: string,
  customerOrderId: string | undefined,
  options: { maxDepth?: number } = {}
) {
  const maxDepth = options.maxDepth ?? 10;

  return useQuery({
    queryKey: customerOrderId
      ? traceKeys.customerOrder(brandSlug, customerOrderId, maxDepth)
      : ["trace", "customer-order", "noop"],
    queryFn: () => traceService.customerOrder(brandSlug, customerOrderId!, { maxDepth }),
    enabled: !!brandSlug && !!customerOrderId,
    staleTime: FIVE_MINUTES,
  });
}
