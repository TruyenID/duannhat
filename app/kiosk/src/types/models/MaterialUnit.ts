/**
 * MaterialUnit Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MaterialUnit as MaterialUnitBase } from './base/MaterialUnit';
import {
  baseMaterialUnitSchemas,
  baseMaterialUnitCreateSchema,
  baseMaterialUnitUpdateSchema,
  materialUnitI18n,
  getMaterialUnitLabel,
  getMaterialUnitFieldLabel,
  getMaterialUnitFieldPlaceholder,
} from './base/MaterialUnit';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MaterialUnit extends MaterialUnitBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const materialUnitSchemas = { ...baseMaterialUnitSchemas };
export const materialUnitCreateSchema = baseMaterialUnitCreateSchema;
export const materialUnitUpdateSchema = baseMaterialUnitUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MaterialUnitCreate = z.infer<typeof materialUnitCreateSchema>;
export type MaterialUnitUpdate = z.infer<typeof materialUnitUpdateSchema>;

// Re-export i18n and helpers
export {
  materialUnitI18n,
  getMaterialUnitLabel,
  getMaterialUnitFieldLabel,
  getMaterialUnitFieldPlaceholder,
};

// Re-export base type for internal use
export type { MaterialUnitBase };
