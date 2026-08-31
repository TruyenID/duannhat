/**
 * VariantUnit Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { VariantUnit as VariantUnitBase } from './base/VariantUnit';
import {
  baseVariantUnitSchemas,
  baseVariantUnitCreateSchema,
  baseVariantUnitUpdateSchema,
  variantUnitI18n,
  getVariantUnitLabel,
  getVariantUnitFieldLabel,
  getVariantUnitFieldPlaceholder,
} from './base/VariantUnit';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface VariantUnit extends VariantUnitBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const variantUnitSchemas = { ...baseVariantUnitSchemas };
export const variantUnitCreateSchema = baseVariantUnitCreateSchema;
export const variantUnitUpdateSchema = baseVariantUnitUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type VariantUnitCreate = z.infer<typeof variantUnitCreateSchema>;
export type VariantUnitUpdate = z.infer<typeof variantUnitUpdateSchema>;

// Re-export i18n and helpers
export {
  variantUnitI18n,
  getVariantUnitLabel,
  getVariantUnitFieldLabel,
  getVariantUnitFieldPlaceholder,
};

// Re-export base type for internal use
export type { VariantUnitBase };
