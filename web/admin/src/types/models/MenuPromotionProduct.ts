/**
 * MenuPromotionProduct Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MenuPromotionProduct as MenuPromotionProductBase } from './base/MenuPromotionProduct';
import {
  baseMenuPromotionProductSchemas,
  baseMenuPromotionProductCreateSchema,
  baseMenuPromotionProductUpdateSchema,
  menuPromotionProductI18n,
  getMenuPromotionProductLabel,
  getMenuPromotionProductFieldLabel,
  getMenuPromotionProductFieldPlaceholder,
} from './base/MenuPromotionProduct';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuPromotionProduct extends MenuPromotionProductBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuPromotionProductSchemas = { ...baseMenuPromotionProductSchemas };
export const menuPromotionProductCreateSchema = baseMenuPromotionProductCreateSchema;
export const menuPromotionProductUpdateSchema = baseMenuPromotionProductUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuPromotionProductCreate = z.infer<typeof menuPromotionProductCreateSchema>;
export type MenuPromotionProductUpdate = z.infer<typeof menuPromotionProductUpdateSchema>;

// Re-export i18n and helpers
export {
  menuPromotionProductI18n,
  getMenuPromotionProductLabel,
  getMenuPromotionProductFieldLabel,
  getMenuPromotionProductFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuPromotionProductBase };
