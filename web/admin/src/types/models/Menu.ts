/**
 * Menu Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Menu as MenuBase } from './base/Menu';
import {
  baseMenuSchemas,
  baseMenuCreateSchema,
  baseMenuUpdateSchema,
  menuI18n,
  getMenuLabel,
  getMenuFieldLabel,
  getMenuFieldPlaceholder,
} from './base/Menu';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Menu extends MenuBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuSchemas = { ...baseMenuSchemas };
export const menuCreateSchema = baseMenuCreateSchema;
export const menuUpdateSchema = baseMenuUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuCreate = z.infer<typeof menuCreateSchema>;
export type MenuUpdate = z.infer<typeof menuUpdateSchema>;

// Re-export i18n and helpers
export {
  menuI18n,
  getMenuLabel,
  getMenuFieldLabel,
  getMenuFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuBase };
