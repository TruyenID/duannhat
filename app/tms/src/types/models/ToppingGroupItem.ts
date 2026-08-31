/**
 * ToppingGroupItem Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ToppingGroupItem as ToppingGroupItemBase } from './base/ToppingGroupItem';
import {
  baseToppingGroupItemSchemas,
  baseToppingGroupItemCreateSchema,
  baseToppingGroupItemUpdateSchema,
  toppingGroupItemI18n,
  getToppingGroupItemLabel,
  getToppingGroupItemFieldLabel,
  getToppingGroupItemFieldPlaceholder,
} from './base/ToppingGroupItem';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ToppingGroupItem extends ToppingGroupItemBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const toppingGroupItemSchemas = { ...baseToppingGroupItemSchemas };
export const toppingGroupItemCreateSchema = baseToppingGroupItemCreateSchema;
export const toppingGroupItemUpdateSchema = baseToppingGroupItemUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ToppingGroupItemCreate = z.infer<typeof toppingGroupItemCreateSchema>;
export type ToppingGroupItemUpdate = z.infer<typeof toppingGroupItemUpdateSchema>;

// Re-export i18n and helpers
export {
  toppingGroupItemI18n,
  getToppingGroupItemLabel,
  getToppingGroupItemFieldLabel,
  getToppingGroupItemFieldPlaceholder,
};

// Re-export base type for internal use
export type { ToppingGroupItemBase };
