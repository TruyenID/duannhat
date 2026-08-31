/**
 * FloatingSectionSchedule Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
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
  // Add custom properties here
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
