/**
 * ToppingGroupItemSku Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ToppingGroupItemSku as ToppingGroupItemSkuBase } from './base/ToppingGroupItemSku';
import {
  baseToppingGroupItemSkuSchemas,
  baseToppingGroupItemSkuCreateSchema,
  baseToppingGroupItemSkuUpdateSchema,
  toppingGroupItemSkuI18n,
  getToppingGroupItemSkuLabel,
  getToppingGroupItemSkuFieldLabel,
  getToppingGroupItemSkuFieldPlaceholder,
} from './base/ToppingGroupItemSku';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ToppingGroupItemSku extends ToppingGroupItemSkuBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const toppingGroupItemSkuSchemas = { ...baseToppingGroupItemSkuSchemas };
export const toppingGroupItemSkuCreateSchema = baseToppingGroupItemSkuCreateSchema;
export const toppingGroupItemSkuUpdateSchema = baseToppingGroupItemSkuUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ToppingGroupItemSkuCreate = z.infer<typeof toppingGroupItemSkuCreateSchema>;
export type ToppingGroupItemSkuUpdate = z.infer<typeof toppingGroupItemSkuUpdateSchema>;

// Re-export i18n and helpers
export {
  toppingGroupItemSkuI18n,
  getToppingGroupItemSkuLabel,
  getToppingGroupItemSkuFieldLabel,
  getToppingGroupItemSkuFieldPlaceholder,
};

// Re-export base type for internal use
export type { ToppingGroupItemSkuBase };
