/**
 * MenuProductToppingItemOverride Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MenuProductToppingItemOverride as MenuProductToppingItemOverrideBase } from './base/MenuProductToppingItemOverride';
import {
  baseMenuProductToppingItemOverrideSchemas,
  baseMenuProductToppingItemOverrideCreateSchema,
  baseMenuProductToppingItemOverrideUpdateSchema,
  menuProductToppingItemOverrideI18n,
  getMenuProductToppingItemOverrideLabel,
  getMenuProductToppingItemOverrideFieldLabel,
  getMenuProductToppingItemOverrideFieldPlaceholder,
} from './base/MenuProductToppingItemOverride';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuProductToppingItemOverride extends MenuProductToppingItemOverrideBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuProductToppingItemOverrideSchemas = { ...baseMenuProductToppingItemOverrideSchemas };
export const menuProductToppingItemOverrideCreateSchema = baseMenuProductToppingItemOverrideCreateSchema;
export const menuProductToppingItemOverrideUpdateSchema = baseMenuProductToppingItemOverrideUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuProductToppingItemOverrideCreate = z.infer<typeof menuProductToppingItemOverrideCreateSchema>;
export type MenuProductToppingItemOverrideUpdate = z.infer<typeof menuProductToppingItemOverrideUpdateSchema>;

// Re-export i18n and helpers
export {
  menuProductToppingItemOverrideI18n,
  getMenuProductToppingItemOverrideLabel,
  getMenuProductToppingItemOverrideFieldLabel,
  getMenuProductToppingItemOverrideFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuProductToppingItemOverrideBase };
