/**
 * Menu Schedule Service — pure TypeScript, no React dependency.
 *
 * All API calls for the MenuSchedule domain.
 * URL convention: /api/v1/hq/{brandSlug}/menus/{menuId}/schedules/...
 *
 * The React-Query layer lives in src/hooks/api/use-menu-schedules.ts.
 */

import { apiFetch } from "@/lib/api";
import type {
  MenuSchedule,
  BranchEffectiveSchedule,
  BranchScheduleOverrideInput,
  CreateMenuScheduleInput,
  UpdateMenuScheduleInput,
} from "@/types/models/MenuSchedule";

// =========================================================================
//  Helpers
// =========================================================================

function baseUrl(brandSlug: string, menuId: string, path = ""): string {
  return `/api/v1/hq/${brandSlug}/menus/${menuId}/schedules${path}`;
}

function shopBaseUrl(shopSlug: string, menuId: string, path = ""): string {
  return `/api/v1/shops/${shopSlug}/menus/${menuId}/schedules${path}`;
}

// =========================================================================
//  Service
// =========================================================================

export const menuScheduleService = {
  list: (brandSlug: string, menuId: string) =>
    apiFetch<{ data: MenuSchedule[] }>(baseUrl(brandSlug, menuId)),

  create: (brandSlug: string, menuId: string, data: CreateMenuScheduleInput) =>
    apiFetch<{ data: MenuSchedule }>(baseUrl(brandSlug, menuId), {
      method: "POST",
      body: JSON.stringify(data),
    }),

  update: (brandSlug: string, menuId: string, scheduleId: string, data: UpdateMenuScheduleInput) =>
    apiFetch<{ data: MenuSchedule }>(baseUrl(brandSlug, menuId, `/${scheduleId}`), {
      method: "PUT",
      body: JSON.stringify(data),
    }),

  delete: (brandSlug: string, menuId: string, scheduleId: string) =>
    apiFetch<void>(baseUrl(brandSlug, menuId, `/${scheduleId}`), {
      method: "DELETE",
    }),

  reorder: (brandSlug: string, menuId: string, scheduleIds: string[]) =>
    apiFetch<void>(baseUrl(brandSlug, menuId, "/reorder"), {
      method: "PUT",
      body: JSON.stringify({ schedule_ids: scheduleIds }),
    }),
};

// =========================================================================
//  Shop schedule override service (shops/{shopSlug}/menus/{menuId}/schedules)
// =========================================================================

export const shopMenuScheduleService = {
  list: (shopSlug: string, menuId: string) =>
    apiFetch<{ data: BranchEffectiveSchedule[] }>(shopBaseUrl(shopSlug, menuId)),

  upsertOverride: (
    shopSlug: string,
    menuId: string,
    scheduleId: string,
    data: BranchScheduleOverrideInput
  ) =>
    apiFetch<{ data: BranchEffectiveSchedule }>(
      shopBaseUrl(shopSlug, menuId, `/${scheduleId}/override`),
      {
        method: "PUT",
        body: JSON.stringify(data),
      }
    ),

  deleteOverride: (shopSlug: string, menuId: string, scheduleId: string) =>
    apiFetch<void>(shopBaseUrl(shopSlug, menuId, `/${scheduleId}/override`), {
      method: "DELETE",
    }),

  /**
   * Activate or pause a schedule window for this shop. Branch menus own their
   * own schedule rows, so this writes is_active directly on the schedule —
   * a paused window stops showing the menu to customers but stays in the
   * shop-manager list so it can be re-activated.
   */
  setActive: (shopSlug: string, menuId: string, scheduleId: string, isActive: boolean) =>
    apiFetch<{ data: BranchEffectiveSchedule }>(
      shopBaseUrl(shopSlug, menuId, `/${scheduleId}/active`),
      {
        method: "PUT",
        body: JSON.stringify({ is_active: isActive }),
      }
    ),
};
