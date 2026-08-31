/**
 * TanStack Query hooks for the authenticated user's notification preferences
 * (plan-012 T3.10 backend + T3.11 frontend).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { preferenceService } from "@/services/notification-preference-service";
import type { NotificationChannel } from "@/services/notification-routing-service";

export interface QuietHoursInput {
  from: string;
  to: string;
  tz: string;
}

export const preferenceKeys = {
  all: () => ["me", "notification-preferences"] as const,
  list: () => ["me", "notification-preferences", "list"] as const,
  types: () => ["me", "notifications", "types"] as const,
};

export function useNotificationPreferences() {
  return useQuery({
    queryKey: preferenceKeys.list(),
    queryFn: () => preferenceService.get(),
  });
}

export function useUserNotificationTypes() {
  return useQuery({
    queryKey: preferenceKeys.types(),
    queryFn: () => preferenceService.types(),
  });
}

export function useUpsertNotificationPreference() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      type,
      channel,
      enabled,
    }: {
      type: string;
      channel: NotificationChannel;
      enabled: boolean;
    }) => preferenceService.upsert(type, channel, enabled),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: preferenceKeys.all() });
    },
  });
}

export function useSetMasterMute() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (masterMute: boolean) => preferenceService.setMasterMute(masterMute),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: preferenceKeys.all() });
    },
  });
}

export function useSetQuietHours() {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (qh: QuietHoursInput) => preferenceService.setQuietHours(qh.from, qh.to, qh.tz),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: preferenceKeys.all() });
    },
  });
}
