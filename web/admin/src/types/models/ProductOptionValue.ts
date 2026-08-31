/**
 * ProductOptionValue Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ProductOptionValue as ProductOptionValueBase } from './base/ProductOptionValue';
import {
  baseProductOptionValueSchemas,
  baseProductOptionValueCreateSchema,
  baseProductOptionValueUpdateSchema,
  productOptionValueI18n,
  getProductOptionValueLabel,
  getProductOptionValueFieldLabel,
  getProductOptionValueFieldPlaceholder,
} from './base/ProductOptionValue';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ProductOptionValue extends ProductOptionValueBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productOptionValueSchemas = { ...baseProductOptionValueSchemas };
export const productOptionValueCreateSchema = baseProductOptionValueCreateSchema;
export const productOptionValueUpdateSchema = baseProductOptionValueUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductOptionValueCreate = z.infer<typeof productOptionValueCreateSchema>;
export type ProductOptionValueUpdate = z.infer<typeof productOptionValueUpdateSchema>;

// Re-export i18n and helpers
export {
  productOptionValueI18n,
  getProductOptionValueLabel,
  getProductOptionValueFieldLabel,
  getProductOptionValueFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductOptionValueBase };
