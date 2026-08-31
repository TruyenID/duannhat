/**
 * MenuItem Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MenuItem as MenuItemBase } from './base/MenuItem';
import {
  baseMenuItemSchemas,
  baseMenuItemCreateSchema,
  baseMenuItemUpdateSchema,
  menuItemI18n,
  getMenuItemLabel,
  getMenuItemFieldLabel,
  getMenuItemFieldPlaceholder,
} from './base/MenuItem';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuItem extends MenuItemBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuItemSchemas = { ...baseMenuItemSchemas };
export const menuItemCreateSchema = baseMenuItemCreateSchema;
export const menuItemUpdateSchema = baseMenuItemUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuItemCreate = z.infer<typeof menuItemCreateSchema>;
export type MenuItemUpdate = z.infer<typeof menuItemUpdateSchema>;

// Re-export i18n and helpers
export {
  menuItemI18n,
  getMenuItemLabel,
  getMenuItemFieldLabel,
  getMenuItemFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuItemBase };
