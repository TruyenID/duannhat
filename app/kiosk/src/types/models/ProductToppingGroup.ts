/**
 * ProductToppingGroup Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { ProductToppingGroup as ProductToppingGroupBase } from './base/ProductToppingGroup';
import {
  baseProductToppingGroupSchemas,
  baseProductToppingGroupCreateSchema,
  baseProductToppingGroupUpdateSchema,
  productToppingGroupI18n,
  getProductToppingGroupLabel,
  getProductToppingGroupFieldLabel,
  getProductToppingGroupFieldPlaceholder,
} from './base/ProductToppingGroup';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ProductToppingGroup extends ProductToppingGroupBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productToppingGroupSchemas = { ...baseProductToppingGroupSchemas };
export const productToppingGroupCreateSchema = baseProductToppingGroupCreateSchema;
export const productToppingGroupUpdateSchema = baseProductToppingGroupUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductToppingGroupCreate = z.infer<typeof productToppingGroupCreateSchema>;
export type ProductToppingGroupUpdate = z.infer<typeof productToppingGroupUpdateSchema>;

// Re-export i18n and helpers
export {
  productToppingGroupI18n,
  getProductToppingGroupLabel,
  getProductToppingGroupFieldLabel,
  getProductToppingGroupFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductToppingGroupBase };
