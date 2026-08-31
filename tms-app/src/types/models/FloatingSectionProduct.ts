/**
 * FloatingSectionProduct Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { FloatingSectionProduct as FloatingSectionProductBase } from './base/FloatingSectionProduct';
import {
  baseFloatingSectionProductSchemas,
  baseFloatingSectionProductCreateSchema,
  baseFloatingSectionProductUpdateSchema,
  floatingSectionProductI18n,
  getFloatingSectionProductLabel,
  getFloatingSectionProductFieldLabel,
  getFloatingSectionProductFieldPlaceholder,
} from './base/FloatingSectionProduct';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface FloatingSectionProduct extends FloatingSectionProductBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const floatingSectionProductSchemas = { ...baseFloatingSectionProductSchemas };
export const floatingSectionProductCreateSchema = baseFloatingSectionProductCreateSchema;
export const floatingSectionProductUpdateSchema = baseFloatingSectionProductUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type FloatingSectionProductCreate = z.infer<typeof floatingSectionProductCreateSchema>;
export type FloatingSectionProductUpdate = z.infer<typeof floatingSectionProductUpdateSchema>;

// Re-export i18n and helpers
export {
  floatingSectionProductI18n,
  getFloatingSectionProductLabel,
  getFloatingSectionProductFieldLabel,
  getFloatingSectionProductFieldPlaceholder,
};

// Re-export base type for internal use
export type { FloatingSectionProductBase };
