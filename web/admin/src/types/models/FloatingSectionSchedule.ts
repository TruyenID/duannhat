/**
 * FloatingSectionSchedule Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { FloatingSectionSchedule as FloatingSectionScheduleBase } from './base/FloatingSectionSchedule';
import {
  baseFloatingSectionScheduleSchemas,
  baseFloatingSectionScheduleCreateSchema,
  baseFloatingSectionScheduleUpdateSchema,
  floatingSectionScheduleI18n,
  getFloatingSectionScheduleLabel,
  getFloatingSectionScheduleFieldLabel,
  getFloatingSectionScheduleFieldPlaceholder,
} from './base/FloatingSectionSchedule';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface FloatingSectionSchedule extends FloatingSectionScheduleBase {
  /** Decoded day labels from days_of_week bitmask, e.g. ["Mon","Tue","Wed"]. Server-computed. */
  days_of_week_labels: string[];
}

// ============================================================================
// Service input types (used by floating-section-schedule-service.ts)
// ============================================================================

export interface CreateFloatingSectionScheduleInput {
  /** Format: "HH:MM" or "HH:MM:SS" */
  start_time: string;
  /** Format: "HH:MM" or "HH:MM:SS". Must be after start_time. */
  end_time: string;
  /** Bitmask 1–127: bit0=Sun … bit6=Sat */
  days_of_week: number;
  is_active?: boolean;
  priority?: number;
}

export interface UpdateFloatingSectionScheduleInput {
  start_time?: string;
  end_time?: string;
  days_of_week?: number;
  is_active?: boolean;
  priority?: number;
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const floatingSectionScheduleSchemas = { ...baseFloatingSectionScheduleSchemas };
export const floatingSectionScheduleCreateSchema = baseFloatingSectionScheduleCreateSchema;
export const floatingSectionScheduleUpdateSchema = baseFloatingSectionScheduleUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type FloatingSectionScheduleCreate = z.infer<typeof floatingSectionScheduleCreateSchema>;
export type FloatingSectionScheduleUpdate = z.infer<typeof floatingSectionScheduleUpdateSchema>;

// Re-export i18n and helpers
export {
  floatingSectionScheduleI18n,
  getFloatingSectionScheduleLabel,
  getFloatingSectionScheduleFieldLabel,
  getFloatingSectionScheduleFieldPlaceholder,
};

// Re-export base type for internal use
export type { FloatingSectionScheduleBase };
