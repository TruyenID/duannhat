/**
 * MenuScheduleDate Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { MenuScheduleDate as MenuScheduleDateBase } from './base/MenuScheduleDate';
import {
  baseMenuScheduleDateSchemas,
  baseMenuScheduleDateCreateSchema,
  baseMenuScheduleDateUpdateSchema,
  menuScheduleDateI18n,
  getMenuScheduleDateLabel,
  getMenuScheduleDateFieldLabel,
  getMenuScheduleDateFieldPlaceholder,
} from './base/MenuScheduleDate';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuScheduleDate extends MenuScheduleDateBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuScheduleDateSchemas = { ...baseMenuScheduleDateSchemas };
export const menuScheduleDateCreateSchema = baseMenuScheduleDateCreateSchema;
export const menuScheduleDateUpdateSchema = baseMenuScheduleDateUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuScheduleDateCreate = z.infer<typeof menuScheduleDateCreateSchema>;
export type MenuScheduleDateUpdate = z.infer<typeof menuScheduleDateUpdateSchema>;

// Re-export i18n and helpers
export {
  menuScheduleDateI18n,
  getMenuScheduleDateLabel,
  getMenuScheduleDateFieldLabel,
  getMenuScheduleDateFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuScheduleDateBase };
