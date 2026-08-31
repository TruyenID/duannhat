/**
 * ProductOption Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ProductOption as ProductOptionBase } from './base/ProductOption';
import {
  baseProductOptionSchemas,
  baseProductOptionCreateSchema,
  baseProductOptionUpdateSchema,
  productOptionI18n,
  getProductOptionLabel,
  getProductOptionFieldLabel,
  getProductOptionFieldPlaceholder,
} from './base/ProductOption';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ProductOption extends ProductOptionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productOptionSchemas = { ...baseProductOptionSchemas };
export const productOptionCreateSchema = baseProductOptionCreateSchema;
export const productOptionUpdateSchema = baseProductOptionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductOptionCreate = z.infer<typeof productOptionCreateSchema>;
export type ProductOptionUpdate = z.infer<typeof productOptionUpdateSchema>;

// Re-export i18n and helpers
export {
  productOptionI18n,
  getProductOptionLabel,
  getProductOptionFieldLabel,
  getProductOptionFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductOptionBase };
