/**
 * MenuSection Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MenuSection as MenuSectionBase } from './base/MenuSection';
import {
  baseMenuSectionSchemas,
  baseMenuSectionCreateSchema,
  baseMenuSectionUpdateSchema,
  menuSectionI18n,
  getMenuSectionLabel,
  getMenuSectionFieldLabel,
  getMenuSectionFieldPlaceholder,
} from './base/MenuSection';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuSection extends MenuSectionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuSectionSchemas = { ...baseMenuSectionSchemas };
export const menuSectionCreateSchema = baseMenuSectionCreateSchema;
export const menuSectionUpdateSchema = baseMenuSectionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuSectionCreate = z.infer<typeof menuSectionCreateSchema>;
export type MenuSectionUpdate = z.infer<typeof menuSectionUpdateSchema>;

// Re-export i18n and helpers
export {
  menuSectionI18n,
  getMenuSectionLabel,
  getMenuSectionFieldLabel,
  getMenuSectionFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuSectionBase };
