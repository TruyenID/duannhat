/**
 * ProductType Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ProductType as ProductTypeBase } from './base/ProductType';
import {
  baseProductTypeSchemas,
  baseProductTypeCreateSchema,
  baseProductTypeUpdateSchema,
  productTypeI18n,
  getProductTypeLabel,
  getProductTypeFieldLabel,
  getProductTypeFieldPlaceholder,
} from './base/ProductType';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ProductType extends ProductTypeBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productTypeSchemas = { ...baseProductTypeSchemas };
export const productTypeCreateSchema = baseProductTypeCreateSchema;
export const productTypeUpdateSchema = baseProductTypeUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductTypeCreate = z.infer<typeof productTypeCreateSchema>;
export type ProductTypeUpdate = z.infer<typeof productTypeUpdateSchema>;

// Re-export i18n and helpers
export {
  productTypeI18n,
  getProductTypeLabel,
  getProductTypeFieldLabel,
  getProductTypeFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductTypeBase };
