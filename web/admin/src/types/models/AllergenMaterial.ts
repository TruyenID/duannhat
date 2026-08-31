/**
 * AllergenMaterial Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { AllergenMaterial as AllergenMaterialBase } from './base/AllergenMaterial';
import {
  baseAllergenMaterialSchemas,
  baseAllergenMaterialCreateSchema,
  baseAllergenMaterialUpdateSchema,
  allergenMaterialI18n,
  getAllergenMaterialLabel,
  getAllergenMaterialFieldLabel,
  getAllergenMaterialFieldPlaceholder,
} from './base/AllergenMaterial';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface AllergenMaterial extends AllergenMaterialBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const allergenMaterialSchemas = { ...baseAllergenMaterialSchemas };
export const allergenMaterialCreateSchema = baseAllergenMaterialCreateSchema;
export const allergenMaterialUpdateSchema = baseAllergenMaterialUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type AllergenMaterialCreate = z.infer<typeof allergenMaterialCreateSchema>;
export type AllergenMaterialUpdate = z.infer<typeof allergenMaterialUpdateSchema>;

// Re-export i18n and helpers
export {
  allergenMaterialI18n,
  getAllergenMaterialLabel,
  getAllergenMaterialFieldLabel,
  getAllergenMaterialFieldPlaceholder,
};

// Re-export base type for internal use
export type { AllergenMaterialBase };
