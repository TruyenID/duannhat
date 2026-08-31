/**
 * MenuMenuSection Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MenuMenuSection as MenuMenuSectionBase } from './base/MenuMenuSection';
import {
  baseMenuMenuSectionSchemas,
  baseMenuMenuSectionCreateSchema,
  baseMenuMenuSectionUpdateSchema,
  menuMenuSectionI18n,
  getMenuMenuSectionLabel,
  getMenuMenuSectionFieldLabel,
  getMenuMenuSectionFieldPlaceholder,
} from './base/MenuMenuSection';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuMenuSection extends MenuMenuSectionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuMenuSectionSchemas = { ...baseMenuMenuSectionSchemas };
export const menuMenuSectionCreateSchema = baseMenuMenuSectionCreateSchema;
export const menuMenuSectionUpdateSchema = baseMenuMenuSectionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuMenuSectionCreate = z.infer<typeof menuMenuSectionCreateSchema>;
export type MenuMenuSectionUpdate = z.infer<typeof menuMenuSectionUpdateSchema>;

// Re-export i18n and helpers
export {
  menuMenuSectionI18n,
  getMenuMenuSectionLabel,
  getMenuMenuSectionFieldLabel,
  getMenuMenuSectionFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuMenuSectionBase };
