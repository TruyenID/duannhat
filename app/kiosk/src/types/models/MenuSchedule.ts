/**
 * MenuSchedule Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
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
  // Add custom properties here
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
