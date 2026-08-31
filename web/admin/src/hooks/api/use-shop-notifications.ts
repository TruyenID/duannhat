/**
 * Plan-023 M6 T6.11 — TanStack Query wrappers for shop notification API.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { shopNotificationService } from "@/services/shop-notification-service";
import type { AudienceRule } from "@/services/notification-audience-service";

const root = (shopSlug: string) => ["shops", shopSlug, "notifications"] as const;

// ─── Audiences ───────────────────────────────────────────────────────────
export function useShopAudiences(shopSlug: string) {
  return useQuery({
    queryKey: [...root(shopSlug), "audiences"] as const,
    queryFn: () => shopNotificationService.audiencesList(shopSlug),
    staleTime: 30_000,
  });
}

export function useShopAudienceCreate(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: { name: string; description?: string; rule: AudienceRule }) =>
      shopNotificationService.audienceCreate(shopSlug, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...root(shopSlug), "audiences"] }),
  });
}

export function useShopAudienceDelete(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => shopNotificationService.audienceDelete(shopSlug, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...root(shopSlug), "audiences"] }),
  });
}

// ─── Templates ───────────────────────────────────────────────────────────
export function useShopTemplates(shopSlug: string) {
  return useQuery({
    queryKey: [...root(shopSlug), "templates"] as const,
    queryFn: () => shopNotificationService.templatesList(shopSlug),
    staleTime: 30_000,
  });
}

export function useShopTemplateCreate(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: Parameters<typeof shopNotificationService.templateCreate>[1]) =>
      shopNotificationService.templateCreate(shopSlug, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...root(shopSlug), "templates"] }),
  });
}

export function useShopTemplateDelete(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => shopNotificationService.templateDelete(shopSlug, id),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...root(shopSlug), "templates"] }),
  });
}

// ─── Routes ──────────────────────────────────────────────────────────────
export function useShopRoutes(shopSlug: string) {
  return useQuery({
    queryKey: [...root(shopSlug), "routes"] as const,
    queryFn: () => shopNotificationService.routesList(shopSlug),
    staleTime: 30_000,
  });
}

export function useShopRouteUpsert(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: Parameters<typeof shopNotificationService.routeUpsert>[1]) =>
      shopNotificationService.routeUpsert(shopSlug, payload),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...root(shopSlug), "routes"] }),
  });
}

export function useShopRouteDelete(shopSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (type: string) => shopNotificationService.routeDelete(shopSlug, type),
    onSuccess: () => qc.invalidateQueries({ queryKey: [...root(shopSlug), "routes"] }),
  });
}

// ─── Broadcast ───────────────────────────────────────────────────────────
export function useShopBroadcast(shopSlug: string) {
  return useMutation({
    mutationFn: (payload: Parameters<typeof shopNotificationService.broadcast>[1]) =>
      shopNotificationService.broadcast(shopSlug, payload),
  });
}

// ─── Audit ───────────────────────────────────────────────────────────────
export function useShopAudit(shopSlug: string, params: { type?: string; priority?: string } = {}) {
  return useQuery({
    queryKey: [...root(shopSlug), "audit", params] as const,
    queryFn: () => shopNotificationService.auditList(shopSlug, params),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}
