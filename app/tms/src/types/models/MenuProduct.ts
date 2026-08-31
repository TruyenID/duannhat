/**
 * MenuProduct Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MenuProduct as MenuProductBase } from './base/MenuProduct';
import {
  baseMenuProductSchemas,
  baseMenuProductCreateSchema,
  baseMenuProductUpdateSchema,
  menuProductI18n,
  getMenuProductLabel,
  getMenuProductFieldLabel,
  getMenuProductFieldPlaceholder,
} from './base/MenuProduct';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuProduct extends MenuProductBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuProductSchemas = { ...baseMenuProductSchemas };
export const menuProductCreateSchema = baseMenuProductCreateSchema;
export const menuProductUpdateSchema = baseMenuProductUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuProductCreate = z.infer<typeof menuProductCreateSchema>;
export type MenuProductUpdate = z.infer<typeof menuProductUpdateSchema>;

// Re-export i18n and helpers
export {
  menuProductI18n,
  getMenuProductLabel,
  getMenuProductFieldLabel,
  getMenuProductFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuProductBase };
