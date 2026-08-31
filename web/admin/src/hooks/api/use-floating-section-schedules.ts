/**
 * Floating Section Schedule Hooks — React wrappers around floatingSectionScheduleService.
 *
 * Mirrors use-menu-schedules.ts. Every mutation invalidates
 * floatingSectionScheduleKeys.all(brandSlug, sectionId).
 */

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { toast } from "sonner";
import { useTranslation } from "@/providers/app-provider";
import { floatingSectionScheduleService } from "@/services/floating-section-schedule-service";
import type {
  CreateFloatingSectionScheduleInput,
  UpdateFloatingSectionScheduleInput,
} from "@/types/models/FloatingSectionSchedule";
import { floatingSectionScheduleKeys, floatingSectionKeys } from "./query-keys";

// =========================================================================
//  Queries
// =========================================================================

export function useFloatingSectionSchedules(brandSlug: string, sectionId: string) {
  return useQuery({
    queryKey: floatingSectionScheduleKeys.list(brandSlug, sectionId),
    queryFn: () => floatingSectionScheduleService.list(brandSlug, sectionId),
    enabled: !!brandSlug && !!sectionId,
  });
}

// =========================================================================
//  Mutations
// =========================================================================

export function useCreateFloatingSectionSchedule(brandSlug: string, sectionId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (data: CreateFloatingSectionScheduleInput) =>
      floatingSectionScheduleService.create(brandSlug, sectionId, data),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.schedules.toast_created"));
      qc.invalidateQueries({ queryKey: floatingSectionScheduleKeys.all(brandSlug, sectionId) });
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) =>
      toast.error(e.message || t("hq.floating_sections.schedules.toast_error")),
  });
}

export function useUpdateFloatingSectionSchedule(brandSlug: string, sectionId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: ({
      scheduleId,
      data,
    }: {
      scheduleId: string;
      data: UpdateFloatingSectionScheduleInput;
    }) => floatingSectionScheduleService.update(brandSlug, sectionId, scheduleId, data),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.schedules.toast_updated"));
      qc.invalidateQueries({ queryKey: floatingSectionScheduleKeys.all(brandSlug, sectionId) });
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) =>
      toast.error(e.message || t("hq.floating_sections.schedules.toast_error")),
  });
}

export function useDeleteFloatingSectionSchedule(brandSlug: string, sectionId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (scheduleId: string) =>
      floatingSectionScheduleService.delete(brandSlug, sectionId, scheduleId),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.schedules.toast_deleted"));
      qc.invalidateQueries({ queryKey: floatingSectionScheduleKeys.all(brandSlug, sectionId) });
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) =>
      toast.error(e.message || t("hq.floating_sections.schedules.toast_error")),
  });
}

export function useReorderFloatingSectionSchedules(brandSlug: string, sectionId: string) {
  const { t } = useTranslation();
  const qc = useQueryClient();
  return useMutation({
    mutationFn: (scheduleIds: string[]) =>
      floatingSectionScheduleService.reorder(brandSlug, sectionId, scheduleIds),
    onSuccess: () => {
      toast.success(t("hq.floating_sections.reorder_saved"));
      qc.invalidateQueries({ queryKey: floatingSectionScheduleKeys.all(brandSlug, sectionId) });
      qc.invalidateQueries({ queryKey: floatingSectionKeys.all(brandSlug) });
    },
    onError: (e: Error) =>
      toast.error(e.message || t("hq.floating_sections.schedules.toast_error")),
  });
}
