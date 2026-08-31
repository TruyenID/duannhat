/**
 * MenuProductSku Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MenuProductSku as MenuProductSkuBase } from './base/MenuProductSku';
import {
  baseMenuProductSkuSchemas,
  baseMenuProductSkuCreateSchema,
  baseMenuProductSkuUpdateSchema,
  menuProductSkuI18n,
  getMenuProductSkuLabel,
  getMenuProductSkuFieldLabel,
  getMenuProductSkuFieldPlaceholder,
} from './base/MenuProductSku';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuProductSku extends MenuProductSkuBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuProductSkuSchemas = { ...baseMenuProductSkuSchemas };
export const menuProductSkuCreateSchema = baseMenuProductSkuCreateSchema;
export const menuProductSkuUpdateSchema = baseMenuProductSkuUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuProductSkuCreate = z.infer<typeof menuProductSkuCreateSchema>;
export type MenuProductSkuUpdate = z.infer<typeof menuProductSkuUpdateSchema>;

// Re-export i18n and helpers
export {
  menuProductSkuI18n,
  getMenuProductSkuLabel,
  getMenuProductSkuFieldLabel,
  getMenuProductSkuFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuProductSkuBase };
