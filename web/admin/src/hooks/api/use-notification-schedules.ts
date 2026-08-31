/**
 * Plan-023 M3 T3.8 — Notification Schedule hooks (TanStack Query).
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import {
  notificationScheduleService,
  type PreviewRrulePayload,
  type SchedulePatchPayload,
  type SchedulePayload,
  type ScheduleListFilters,
} from "@/services/notification-schedule-service";

export const notificationScheduleKeys = {
  all: (brandSlug: string) => ["notifications", "schedules", brandSlug] as const,
  list: (brandSlug: string, filters?: ScheduleListFilters) =>
    ["notifications", "schedules", brandSlug, "list", filters] as const,
  detail: (brandSlug: string, id: string) =>
    ["notifications", "schedules", brandSlug, "detail", id] as const,
  preview: (brandSlug: string, payload: PreviewRrulePayload) =>
    ["notifications", "schedules", brandSlug, "preview", payload] as const,
};

export function useNotificationSchedules(brandSlug: string, filters: ScheduleListFilters = {}) {
  return useQuery({
    queryKey: notificationScheduleKeys.list(brandSlug, filters),
    queryFn: () => notificationScheduleService.list(brandSlug, filters),
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  });
}

export function useNotificationSchedule(brandSlug: string, id: string | null) {
  return useQuery({
    queryKey: notificationScheduleKeys.detail(brandSlug, id ?? ""),
    queryFn: () => notificationScheduleService.show(brandSlug, id!),
    enabled: !!id,
  });
}

export function useCreateNotificationSchedule(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (payload: SchedulePayload) =>
      notificationScheduleService.create(brandSlug, payload),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationScheduleKeys.all(brandSlug) });
    },
  });
}

export function useUpdateNotificationSchedule(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({ id, payload }: { id: string; payload: SchedulePatchPayload }) =>
      notificationScheduleService.update(brandSlug, id, payload),
    onSuccess: (_data, variables) => {
      qc.invalidateQueries({ queryKey: notificationScheduleKeys.all(brandSlug) });
      qc.invalidateQueries({ queryKey: notificationScheduleKeys.detail(brandSlug, variables.id) });
    },
  });
}

export function useCancelNotificationSchedule(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationScheduleService.cancel(brandSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationScheduleKeys.all(brandSlug) });
    },
  });
}

export function usePauseNotificationSchedule(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationScheduleService.pause(brandSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationScheduleKeys.all(brandSlug) });
    },
  });
}

export function useResumeNotificationSchedule(brandSlug: string) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (id: string) => notificationScheduleService.resume(brandSlug, id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: notificationScheduleKeys.all(brandSlug) });
    },
  });
}

export function usePreviewRrule(brandSlug: string, payload: PreviewRrulePayload | null) {
  return useQuery({
    queryKey: notificationScheduleKeys.preview(
      brandSlug,
      payload ?? { rrule: "", timezone: "", starts_at: "" }
    ),
    queryFn: () => notificationScheduleService.previewRrule(brandSlug, payload!),
    enabled: !!payload && payload.rrule.length > 0,
    staleTime: 30_000,
  });
}
