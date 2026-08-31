/**
 * FloatingSectionProductToppingItemOverride Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { FloatingSectionProductToppingItemOverride as FloatingSectionProductToppingItemOverrideBase } from './base/FloatingSectionProductToppingItemOverride';
import {
  baseFloatingSectionProductToppingItemOverrideSchemas,
  baseFloatingSectionProductToppingItemOverrideCreateSchema,
  baseFloatingSectionProductToppingItemOverrideUpdateSchema,
  floatingSectionProductToppingItemOverrideI18n,
  getFloatingSectionProductToppingItemOverrideLabel,
  getFloatingSectionProductToppingItemOverrideFieldLabel,
  getFloatingSectionProductToppingItemOverrideFieldPlaceholder,
} from './base/FloatingSectionProductToppingItemOverride';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface FloatingSectionProductToppingItemOverride extends FloatingSectionProductToppingItemOverrideBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const floatingSectionProductToppingItemOverrideSchemas = { ...baseFloatingSectionProductToppingItemOverrideSchemas };
export const floatingSectionProductToppingItemOverrideCreateSchema = baseFloatingSectionProductToppingItemOverrideCreateSchema;
export const floatingSectionProductToppingItemOverrideUpdateSchema = baseFloatingSectionProductToppingItemOverrideUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type FloatingSectionProductToppingItemOverrideCreate = z.infer<typeof floatingSectionProductToppingItemOverrideCreateSchema>;
export type FloatingSectionProductToppingItemOverrideUpdate = z.infer<typeof floatingSectionProductToppingItemOverrideUpdateSchema>;

// Re-export i18n and helpers
export {
  floatingSectionProductToppingItemOverrideI18n,
  getFloatingSectionProductToppingItemOverrideLabel,
  getFloatingSectionProductToppingItemOverrideFieldLabel,
  getFloatingSectionProductToppingItemOverrideFieldPlaceholder,
};

// Re-export base type for internal use
export type { FloatingSectionProductToppingItemOverrideBase };
