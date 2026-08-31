/**
 * MenuPromotion Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { MenuPromotion as MenuPromotionBase } from './base/MenuPromotion';
import {
  baseMenuPromotionSchemas,
  baseMenuPromotionCreateSchema,
  baseMenuPromotionUpdateSchema,
  menuPromotionI18n,
  getMenuPromotionLabel,
  getMenuPromotionFieldLabel,
  getMenuPromotionFieldPlaceholder,
} from './base/MenuPromotion';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuPromotion extends MenuPromotionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuPromotionSchemas = { ...baseMenuPromotionSchemas };
export const menuPromotionCreateSchema = baseMenuPromotionCreateSchema;
export const menuPromotionUpdateSchema = baseMenuPromotionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuPromotionCreate = z.infer<typeof menuPromotionCreateSchema>;
export type MenuPromotionUpdate = z.infer<typeof menuPromotionUpdateSchema>;

// Re-export i18n and helpers
export {
  menuPromotionI18n,
  getMenuPromotionLabel,
  getMenuPromotionFieldLabel,
  getMenuPromotionFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuPromotionBase };
