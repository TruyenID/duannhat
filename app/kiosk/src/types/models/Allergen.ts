/**
 * Allergen Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { Allergen as AllergenBase } from './base/Allergen';
import {
  baseAllergenSchemas,
  baseAllergenCreateSchema,
  baseAllergenUpdateSchema,
  allergenI18n,
  getAllergenLabel,
  getAllergenFieldLabel,
  getAllergenFieldPlaceholder,
} from './base/Allergen';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Allergen extends AllergenBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const allergenSchemas = { ...baseAllergenSchemas };
export const allergenCreateSchema = baseAllergenCreateSchema;
export const allergenUpdateSchema = baseAllergenUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type AllergenCreate = z.infer<typeof allergenCreateSchema>;
export type AllergenUpdate = z.infer<typeof allergenUpdateSchema>;

// Re-export i18n and helpers
export {
  allergenI18n,
  getAllergenLabel,
  getAllergenFieldLabel,
  getAllergenFieldPlaceholder,
};

// Re-export base type for internal use
export type { AllergenBase };
