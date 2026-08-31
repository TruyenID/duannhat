/**
 * Plan-023 M4 T4.9 — TanStack Query wrappers for the suppression admin API.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  notificationEmailSuppressionService,
  type SuppressionFilters,
  type SuppressionReason,
} from "@/services/notification-email-suppression-service";

export const suppressionKeys = {
  all: (brandSlug: string) => ["notifications", "email-suppressions", brandSlug] as const,
  list: (brandSlug: string, filters?: SuppressionFilters) =>
    ["notifications", "email-suppressions", brandSlug, "list", filters] as const,
  metrics: (brandSlug: string, range?: { from?: string; to?: string }) =>
    ["notifications", "email-health-metrics", brandSlug, range] as const,
  timeseries: (brandSlug: string, range?: { from?: string; to?: string }) =>
    ["notifications", "email-health-timeseries", brandSlug, range] as const,
};

export function useEmailHealthTimeseries(
  brandSlug: string,
  range: { from?: string; to?: string } = {},
  options: { enabled?: boolean } = {}
) {
  return useQuery({
    queryKey: suppressionKeys.timeseries(brandSlug, range),
    queryFn: () => notificationEmailSuppressionService.timeseries(brandSlug, range),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    enabled: options.enabled ?? true,
  });
}

export function useEmailHealthMetrics(
  brandSlug: string,
  range: { from?: string; to?: string } = {},
  options: { enabled?: boolean } = {}
) {
  return useQuery({
    queryKey: suppressionKeys.metrics(brandSlug, range),
    queryFn: () => notificationEmailSuppressionService.metrics(brandSlug, range),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    enabled: options.enabled ?? true,
  });
}

export function useEmailSuppressions(
  brandSlug: string,
  filters: SuppressionFilters = {},
  options: { enabled?: boolean } = {}
) {
  return useQuery({
    queryKey: suppressionKeys.list(brandSlug, filters),
    queryFn: () => notificationEmailSuppressionService.list(brandSlug, filters),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    enabled: options.enabled ?? true,
  });
}

export function useStoreEmailSuppression(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: { email: string; reason?: SuppressionReason }) =>
      notificationEmailSuppressionService.store(brandSlug, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: suppressionKeys.all(brandSlug) }),
  });
}

export function useUnsuppressEmail(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationEmailSuppressionService.unsuppress(brandSlug, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: suppressionKeys.all(brandSlug) }),
  });
}
