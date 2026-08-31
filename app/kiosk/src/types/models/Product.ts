/**
 * Product Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Product as ProductBase } from './base/Product';
import {
  baseProductSchemas,
  baseProductCreateSchema,
  baseProductUpdateSchema,
  productI18n,
  getProductLabel,
  getProductFieldLabel,
  getProductFieldPlaceholder,
} from './base/Product';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Product extends ProductBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productSchemas = { ...baseProductSchemas };
export const productCreateSchema = baseProductCreateSchema;
export const productUpdateSchema = baseProductUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductCreate = z.infer<typeof productCreateSchema>;
export type ProductUpdate = z.infer<typeof productUpdateSchema>;

// Re-export i18n and helpers
export {
  productI18n,
  getProductLabel,
  getProductFieldLabel,
  getProductFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductBase };
