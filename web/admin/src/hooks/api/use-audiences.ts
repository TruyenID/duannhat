/**
 * TanStack Query hooks for the notification-audience admin CRUD endpoints
 * (plan-012 T1.5).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  audienceService,
  type AudienceRule,
  type StoreAudienceInput,
  type UpdateAudienceInput,
} from "@/services/notification-audience-service";

export const audienceKeys = {
  all: (brandSlug: string) => ["hq", brandSlug, "audiences"] as const,
  list: (brandSlug: string) => ["hq", brandSlug, "audiences", "list"] as const,
  detail: (brandSlug: string, id: string) => ["hq", brandSlug, "audiences", "detail", id] as const,
  preview: (brandSlug: string, rule: AudienceRule | null) =>
    ["hq", brandSlug, "audiences", "preview", rule] as const,
};

export function useAudiences(brandSlug: string) {
  return useQuery({
    queryKey: audienceKeys.list(brandSlug),
    queryFn: () => audienceService.list(brandSlug),
    enabled: !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useAudience(brandSlug: string, id: string | null) {
  return useQuery({
    queryKey: audienceKeys.detail(brandSlug, id ?? ""),
    queryFn: () => audienceService.show(brandSlug, id!),
    enabled: !!brandSlug && !!id,
  });
}

export function useCreateAudience(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: StoreAudienceInput) => audienceService.create(brandSlug, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: audienceKeys.all(brandSlug) });
    },
  });
}

export function useUpdateAudience(brandSlug: string, id: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: UpdateAudienceInput) => audienceService.update(brandSlug, id, input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: audienceKeys.all(brandSlug) });
    },
  });
}

export function useDeleteAudience(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => audienceService.destroy(brandSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: audienceKeys.all(brandSlug) });
    },
  });
}

/**
 * Live preview — disabled unless the caller passes a non-null rule. Debouncing
 * is the caller's job (wrap the input in `useDebouncedValue` before passing
 * here).
 */
export function useAudiencePreview(brandSlug: string, rule: AudienceRule | null) {
  return useQuery({
    queryKey: audienceKeys.preview(brandSlug, rule),
    queryFn: () => audienceService.preview(brandSlug, rule!),
    enabled: !!brandSlug && rule !== null,
    staleTime: 2_000,
    retry: false,
  });
}
