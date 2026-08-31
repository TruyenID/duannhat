/**
 * MenuPromotionCategory Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MenuPromotionCategory as MenuPromotionCategoryBase } from './base/MenuPromotionCategory';
import {
  baseMenuPromotionCategorySchemas,
  baseMenuPromotionCategoryCreateSchema,
  baseMenuPromotionCategoryUpdateSchema,
  menuPromotionCategoryI18n,
  getMenuPromotionCategoryLabel,
  getMenuPromotionCategoryFieldLabel,
  getMenuPromotionCategoryFieldPlaceholder,
} from './base/MenuPromotionCategory';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuPromotionCategory extends MenuPromotionCategoryBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuPromotionCategorySchemas = { ...baseMenuPromotionCategorySchemas };
export const menuPromotionCategoryCreateSchema = baseMenuPromotionCategoryCreateSchema;
export const menuPromotionCategoryUpdateSchema = baseMenuPromotionCategoryUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuPromotionCategoryCreate = z.infer<typeof menuPromotionCategoryCreateSchema>;
export type MenuPromotionCategoryUpdate = z.infer<typeof menuPromotionCategoryUpdateSchema>;

// Re-export i18n and helpers
export {
  menuPromotionCategoryI18n,
  getMenuPromotionCategoryLabel,
  getMenuPromotionCategoryFieldLabel,
  getMenuPromotionCategoryFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuPromotionCategoryBase };
