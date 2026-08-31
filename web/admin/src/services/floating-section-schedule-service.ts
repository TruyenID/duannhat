/**
 * Floating Section Schedule Service — pure TypeScript, no React dependency.
 *
 * All API calls for the FloatingSectionSchedule domain.
 * URL convention: /api/v1/hq/{brandSlug}/floating-sections/{sectionId}/schedules/...
 *
 * The React-Query layer lives in src/hooks/api/use-floating-section-schedules.ts.
 */

import { apiFetch } from "@/lib/api";
import type {
  FloatingSectionSchedule,
  CreateFloatingSectionScheduleInput,
  UpdateFloatingSectionScheduleInput,
} from "@/types/models/FloatingSectionSchedule";

// =========================================================================
//  Helpers
// =========================================================================

function baseUrl(brandSlug: string, sectionId: string, path = ""): string {
  return `/api/v1/hq/${brandSlug}/floating-sections/${sectionId}/schedules${path}`;
}

// =========================================================================
//  Service
// =========================================================================

export const floatingSectionScheduleService = {
  list: (brandSlug: string, sectionId: string) =>
    apiFetch<{ data: FloatingSectionSchedule[] }>(baseUrl(brandSlug, sectionId)),

  create: (brandSlug: string, sectionId: string, data: CreateFloatingSectionScheduleInput) =>
    apiFetch<{ data: FloatingSectionSchedule }>(baseUrl(brandSlug, sectionId), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (
    brandSlug: string,
    sectionId: string,
    scheduleId: string,
    data: UpdateFloatingSectionScheduleInput
  ) =>
    apiFetch<{ data: FloatingSectionSchedule }>(baseUrl(brandSlug, sectionId, `/${scheduleId}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, sectionId: string, scheduleId: string) =>
    apiFetch<void>(baseUrl(brandSlug, sectionId, `/${scheduleId}`), {
      method: "DELETE",
    }),

  reorder: (brandSlug: string, sectionId: string, scheduleIds: string[]) =>
    apiFetch<void>(baseUrl(brandSlug, sectionId, "/reorder"), {
      method: "PUT",
      body: JSON.stringify({ schedule_ids: scheduleIds }),
    }),
};
