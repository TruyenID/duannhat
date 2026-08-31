/**
 * CategoryProduct Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CategoryProduct as CategoryProductBase } from './base/CategoryProduct';
import {
  baseCategoryProductSchemas,
  baseCategoryProductCreateSchema,
  baseCategoryProductUpdateSchema,
  categoryProductI18n,
  getCategoryProductLabel,
  getCategoryProductFieldLabel,
  getCategoryProductFieldPlaceholder,
} from './base/CategoryProduct';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CategoryProduct extends CategoryProductBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const categoryProductSchemas = { ...baseCategoryProductSchemas };
export const categoryProductCreateSchema = baseCategoryProductCreateSchema;
export const categoryProductUpdateSchema = baseCategoryProductUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CategoryProductCreate = z.infer<typeof categoryProductCreateSchema>;
export type CategoryProductUpdate = z.infer<typeof categoryProductUpdateSchema>;

// Re-export i18n and helpers
export {
  categoryProductI18n,
  getCategoryProductLabel,
  getCategoryProductFieldLabel,
  getCategoryProductFieldPlaceholder,
};

// Re-export base type for internal use
export type { CategoryProductBase };
