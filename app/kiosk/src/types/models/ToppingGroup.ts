/**
 * ToppingGroup Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { ToppingGroup as ToppingGroupBase } from './base/ToppingGroup';
import {
  baseToppingGroupSchemas,
  baseToppingGroupCreateSchema,
  baseToppingGroupUpdateSchema,
  toppingGroupI18n,
  getToppingGroupLabel,
  getToppingGroupFieldLabel,
  getToppingGroupFieldPlaceholder,
} from './base/ToppingGroup';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ToppingGroup extends ToppingGroupBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const toppingGroupSchemas = { ...baseToppingGroupSchemas };
export const toppingGroupCreateSchema = baseToppingGroupCreateSchema;
export const toppingGroupUpdateSchema = baseToppingGroupUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ToppingGroupCreate = z.infer<typeof toppingGroupCreateSchema>;
export type ToppingGroupUpdate = z.infer<typeof toppingGroupUpdateSchema>;

// Re-export i18n and helpers
export {
  toppingGroupI18n,
  getToppingGroupLabel,
  getToppingGroupFieldLabel,
  getToppingGroupFieldPlaceholder,
};

// Re-export base type for internal use
export type { ToppingGroupBase };
