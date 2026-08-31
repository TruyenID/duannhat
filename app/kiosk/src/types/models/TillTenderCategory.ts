/**
 * TillTenderCategory Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { TillTenderCategory as TillTenderCategoryBase } from './base/TillTenderCategory';
import {
  baseTillTenderCategorySchemas,
  baseTillTenderCategoryCreateSchema,
  baseTillTenderCategoryUpdateSchema,
  tillTenderCategoryI18n,
  getTillTenderCategoryLabel,
  getTillTenderCategoryFieldLabel,
  getTillTenderCategoryFieldPlaceholder,
} from './base/TillTenderCategory';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TillTenderCategory extends TillTenderCategoryBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tillTenderCategorySchemas = { ...baseTillTenderCategorySchemas };
export const tillTenderCategoryCreateSchema = baseTillTenderCategoryCreateSchema;
export const tillTenderCategoryUpdateSchema = baseTillTenderCategoryUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TillTenderCategoryCreate = z.infer<typeof tillTenderCategoryCreateSchema>;
export type TillTenderCategoryUpdate = z.infer<typeof tillTenderCategoryUpdateSchema>;

// Re-export i18n and helpers
export {
  tillTenderCategoryI18n,
  getTillTenderCategoryLabel,
  getTillTenderCategoryFieldLabel,
  getTillTenderCategoryFieldPlaceholder,
};

// Re-export base type for internal use
export type { TillTenderCategoryBase };
