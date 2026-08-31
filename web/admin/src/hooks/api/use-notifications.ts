/**
 * Notification Hooks — TanStack Query wrappers for /me/notifications/*.
 *
 * Summary (bell badge) polls every 30s + refetches on window focus.
 * Inbox list only fetches when `enabled` is true (dropdown open / inbox page mounted).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  notificationAdminService,
  notificationService,
  type AdminNotificationFilters,
  type InboxFilters,
} from "@/services/notification-service";

export const notificationKeys = {
  all: () => ["notifications"] as const,
  summary: () => ["notifications", "summary"] as const,
  inbox: (filters?: InboxFilters) => ["notifications", "inbox", filters] as const,
};

export const notificationAdminKeys = {
  all: (brandSlug: string) => ["notifications", "admin", brandSlug] as const,
  list: (brandSlug: string, filters?: AdminNotificationFilters) =>
    ["notifications", "admin", brandSlug, "list", filters] as const,
  detail: (brandSlug: string, id: string) =>
    ["notifications", "admin", brandSlug, "detail", id] as const,
  types: (brandSlug: string) => ["notifications", "admin", brandSlug, "types"] as const,
  stats: (brandSlug: string) => ["notifications", "admin", brandSlug, "stats"] as const,
};

export function useNotificationSummary(options: { enabled?: boolean } = {}) {
  return useQuery({
    queryKey: notificationKeys.summary(),
    queryFn: () => notificationService.summary(),
    enabled: options.enabled ?? true,
    refetchInterval: 30_000,
    refetchOnWindowFocus: true,
    staleTime: 15_000,
  });
}

export function useNotificationInbox(
  filters: InboxFilters = {},
  options: { enabled?: boolean } = {}
) {
  return useQuery({
    queryKey: notificationKeys.inbox(filters),
    queryFn: () => notificationService.list(filters),
    enabled: options.enabled ?? true,
    placeholderData: keepPreviousData,
  });
}

export function useMarkNotificationRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationService.markRead(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationKeys.all() });
    },
  });
}

export function useMarkNotificationSeen() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationService.markSeen(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationKeys.all() });
    },
  });
}

export function useDismissNotification() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationService.dismiss(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationKeys.all() });
    },
  });
}

export function useBulkMarkRead() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { ids?: string[]; all_unread?: boolean }) =>
      notificationService.bulkRead(input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationKeys.all() });
    },
  });
}

export function useBulkDismiss() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (input: { ids?: string[]; all_read?: boolean; all?: boolean }) =>
      notificationService.bulkDismiss(input),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationKeys.all() });
    },
  });
}

// =========================================================================
//  HQ admin audit hooks
// =========================================================================

export function useAdminNotificationList(
  brandSlug: string,
  filters: AdminNotificationFilters = {},
  options: { enabled?: boolean } = {}
) {
  return useQuery({
    queryKey: notificationAdminKeys.list(brandSlug, filters),
    queryFn: () => notificationAdminService.list(brandSlug, filters),
    enabled: (options.enabled ?? true) && !!brandSlug,
    placeholderData: keepPreviousData,
  });
}

export function useAdminNotificationDetail(
  brandSlug: string,
  id: string | null,
  options: { enabled?: boolean } = {}
) {
  return useQuery({
    queryKey: notificationAdminKeys.detail(brandSlug, id ?? ""),
    queryFn: () => notificationAdminService.detail(brandSlug, id!),
    enabled: (options.enabled ?? true) && !!brandSlug && !!id,
  });
}

export function useAdminNotificationTypes(brandSlug: string) {
  return useQuery({
    queryKey: notificationAdminKeys.types(brandSlug),
    queryFn: () => notificationAdminService.types(brandSlug),
    enabled: !!brandSlug,
    staleTime: 5 * 60 * 1000,
  });
}

export function useAdminNotificationStats(brandSlug: string) {
  return useQuery({
    queryKey: notificationAdminKeys.stats(brandSlug),
    queryFn: () => notificationAdminService.stats(brandSlug),
    enabled: !!brandSlug,
    staleTime: 60 * 1000,
  });
}
