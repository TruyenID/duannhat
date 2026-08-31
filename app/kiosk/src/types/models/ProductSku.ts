/**
 * ProductSku Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ProductSku as ProductSkuBase } from './base/ProductSku';
import {
  baseProductSkuSchemas,
  baseProductSkuCreateSchema,
  baseProductSkuUpdateSchema,
  productSkuI18n,
  getProductSkuLabel,
  getProductSkuFieldLabel,
  getProductSkuFieldPlaceholder,
} from './base/ProductSku';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ProductSku extends ProductSkuBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productSkuSchemas = { ...baseProductSkuSchemas };
export const productSkuCreateSchema = baseProductSkuCreateSchema;
export const productSkuUpdateSchema = baseProductSkuUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductSkuCreate = z.infer<typeof productSkuCreateSchema>;
export type ProductSkuUpdate = z.infer<typeof productSkuUpdateSchema>;

// Re-export i18n and helpers
export {
  productSkuI18n,
  getProductSkuLabel,
  getProductSkuFieldLabel,
  getProductSkuFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductSkuBase };
