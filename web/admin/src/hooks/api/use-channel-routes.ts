/**
 * TanStack Query hooks for notification channel routes (plan-012 T3.11).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  routingService,
  type UpsertChannelRouteInput,
} from "@/services/notification-routing-service";

export const channelRouteKeys = {
  all: (brandSlug: string) => ["hq", brandSlug, "channel-routes"] as const,
  list: (brandSlug: string) => ["hq", brandSlug, "channel-routes", "list"] as const,
};

export function useChannelRoutes(brandSlug: string) {
  return useQuery({
    queryKey: channelRouteKeys.list(brandSlug),
    queryFn: () => routingService.list(brandSlug),
    enabled: !!brandSlug,
  });
}

export function useUpsertChannelRoutes(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (routes: UpsertChannelRouteInput[]) => routingService.upsert(brandSlug, routes),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: channelRouteKeys.all(brandSlug) });
    },
  });
}

export function useDeleteChannelRoute(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (type: string) => routingService.destroy(brandSlug, type),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: channelRouteKeys.all(brandSlug) });
    },
  });
}
