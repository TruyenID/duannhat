/**
 * Material Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Material as MaterialBase } from './base/Material';
import {
  baseMaterialSchemas,
  baseMaterialCreateSchema,
  baseMaterialUpdateSchema,
  materialI18n,
  getMaterialLabel,
  getMaterialFieldLabel,
  getMaterialFieldPlaceholder,
} from './base/Material';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Material extends MaterialBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const materialSchemas = { ...baseMaterialSchemas };
export const materialCreateSchema = baseMaterialCreateSchema;
export const materialUpdateSchema = baseMaterialUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MaterialCreate = z.infer<typeof materialCreateSchema>;
export type MaterialUpdate = z.infer<typeof materialUpdateSchema>;

// Re-export i18n and helpers
export {
  materialI18n,
  getMaterialLabel,
  getMaterialFieldLabel,
  getMaterialFieldPlaceholder,
};

// Re-export base type for internal use
export type { MaterialBase };
