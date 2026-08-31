/**
 * MenuSchedule Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MenuSchedule as MenuScheduleBase } from './base/MenuSchedule';
import {
  baseMenuScheduleSchemas,
  baseMenuScheduleCreateSchema,
  baseMenuScheduleUpdateSchema,
  menuScheduleI18n,
  getMenuScheduleLabel,
  getMenuScheduleFieldLabel,
  getMenuScheduleFieldPlaceholder,
} from './base/MenuSchedule';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuSchedule extends MenuScheduleBase {
  /** Decoded day labels from days_of_week bitmask, e.g. ["Mon","Tue","Wed"]. Server-computed. */
  days_of_week_labels: string[];
  /** Decoded day numbers from days_of_month (#1979), e.g. [1, 15]. Server-computed. */
  days_of_month_list?: number[];
  /**
   * `YYYY-MM-DD` list for recurrence_kind = SpecificDates (#1979). Always an
   * array, never null — an unloaded relation serialises as [], so a client can
   * map() without a guard.
   */
  specific_dates?: string[];
}

// ============================================================================
// Branch override types
// ============================================================================

/** Response shape from GET /branch/{branchSlug}/menus/{menu}/schedules */
export interface BranchEffectiveSchedule
  extends Omit<MenuSchedule, "start_time" | "end_time" | "start_date" | "end_date"> {
  /** Effective (COALESCED) value — branch override if set, otherwise HQ default */
  start_time: string;
  end_time: string;
  /**
   * Effective campaign window, `YYYY-MM-DD` (#1970). null means UNBOUNDED, not
   * "unknown" — outside the window the menu is off everywhere: POS, guest and
   * the offline LAN till alike.
   */
  start_date: string | null;
  end_date: string | null;
  is_overridden: boolean;
  /** Raw HQ schedule values — always present for the "HQ default: …" tooltip */
  hq_defaults: {
    start_time: string;
    end_time: string;
    /** Bitmask bit0=Sun … bit6=Sat */
    days_of_week: number;
    days_of_week_labels: string[];
    start_date: string | null;
    end_date: string | null;
  };
}

export type BranchScheduleOverrideInput = {
  start_time?: string | null; // null = reset this field to HQ default
  end_time?: string | null;
  /** Bitmask 1–127 (bit0=Sun … bit6=Sat); null = reset to HQ days */
  days_of_week?: number | null;
  /**
   * Campaign window, `YYYY-MM-DD` (#1970). null = reset to the HQ date — which
   * is ITSELF null-means-unbounded, so a shop can narrow or shift the window HQ
   * set but cannot clear one HQ still has.
   */
  start_date?: string | null;
  end_date?: string | null;
  is_active?: boolean;
};

// ============================================================================
// Service input types (used by menu-schedule-service.ts)
// ============================================================================

export interface CreateMenuScheduleInput {
  /** #1979. Omitted means Weekly — what every row meant before the field existed. */
  recurrence_kind?: "Weekly" | "Monthly" | "SpecificDates";
  /** Bitmask, bit0 = the 1st. Required when recurrence_kind is Monthly. */
  days_of_month?: number | null;
  /** `YYYY-MM-DD`. Required when recurrence_kind is SpecificDates. */
  specific_dates?: string[] | null;
  /** Format: "HH:MM" or "HH:MM:SS" */
  start_time: string;
  /** Format: "HH:MM" or "HH:MM:SS". Must be after start_time. */
  end_time: string;
  /** Bitmask 1–127: bit0=Sun … bit6=Sat */
  days_of_week: number;
  is_active?: boolean;
  priority?: number;
}

export interface UpdateMenuScheduleInput {
  recurrence_kind?: "Weekly" | "Monthly" | "SpecificDates";
  days_of_month?: number | null;
  specific_dates?: string[] | null;
  start_time?: string;
  end_time?: string;
  days_of_week?: number;
  is_active?: boolean;
  priority?: number;
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuScheduleSchemas = { ...baseMenuScheduleSchemas };
export const menuScheduleCreateSchema = baseMenuScheduleCreateSchema;
export const menuScheduleUpdateSchema = baseMenuScheduleUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuScheduleCreate = z.infer<typeof menuScheduleCreateSchema>;
export type MenuScheduleUpdate = z.infer<typeof menuScheduleUpdateSchema>;

// Re-export i18n and helpers
export {
  menuScheduleI18n,
  getMenuScheduleLabel,
  getMenuScheduleFieldLabel,
  getMenuScheduleFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuScheduleBase };
