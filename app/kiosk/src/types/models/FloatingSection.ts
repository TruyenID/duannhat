/**
 * FloatingSection Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { FloatingSection as FloatingSectionBase } from './base/FloatingSection';
import {
  baseFloatingSectionSchemas,
  baseFloatingSectionCreateSchema,
  baseFloatingSectionUpdateSchema,
  floatingSectionI18n,
  getFloatingSectionLabel,
  getFloatingSectionFieldLabel,
  getFloatingSectionFieldPlaceholder,
} from './base/FloatingSection';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface FloatingSection extends FloatingSectionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const floatingSectionSchemas = { ...baseFloatingSectionSchemas };
export const floatingSectionCreateSchema = baseFloatingSectionCreateSchema;
export const floatingSectionUpdateSchema = baseFloatingSectionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type FloatingSectionCreate = z.infer<typeof floatingSectionCreateSchema>;
export type FloatingSectionUpdate = z.infer<typeof floatingSectionUpdateSchema>;

// Re-export i18n and helpers
export {
  floatingSectionI18n,
  getFloatingSectionLabel,
  getFloatingSectionFieldLabel,
  getFloatingSectionFieldPlaceholder,
};

// Re-export base type for internal use
export type { FloatingSectionBase };
