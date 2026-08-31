/**
 * Category Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Category as CategoryBase } from './base/Category';
import {
  baseCategorySchemas,
  baseCategoryCreateSchema,
  baseCategoryUpdateSchema,
  categoryI18n,
  getCategoryLabel,
  getCategoryFieldLabel,
  getCategoryFieldPlaceholder,
} from './base/Category';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Category extends CategoryBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const categorySchemas = { ...baseCategorySchemas };
export const categoryCreateSchema = baseCategoryCreateSchema;
export const categoryUpdateSchema = baseCategoryUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CategoryCreate = z.infer<typeof categoryCreateSchema>;
export type CategoryUpdate = z.infer<typeof categoryUpdateSchema>;

// Re-export i18n and helpers
export {
  categoryI18n,
  getCategoryLabel,
  getCategoryFieldLabel,
  getCategoryFieldPlaceholder,
};

// Re-export base type for internal use
export type { CategoryBase };
