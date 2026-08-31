/**
 * ProductToppingGroupItemOverride Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ProductToppingGroupItemOverride as ProductToppingGroupItemOverrideBase } from './base/ProductToppingGroupItemOverride';
import {
  baseProductToppingGroupItemOverrideSchemas,
  baseProductToppingGroupItemOverrideCreateSchema,
  baseProductToppingGroupItemOverrideUpdateSchema,
  productToppingGroupItemOverrideI18n,
  getProductToppingGroupItemOverrideLabel,
  getProductToppingGroupItemOverrideFieldLabel,
  getProductToppingGroupItemOverrideFieldPlaceholder,
} from './base/ProductToppingGroupItemOverride';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ProductToppingGroupItemOverride extends ProductToppingGroupItemOverrideBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productToppingGroupItemOverrideSchemas = { ...baseProductToppingGroupItemOverrideSchemas };
export const productToppingGroupItemOverrideCreateSchema = baseProductToppingGroupItemOverrideCreateSchema;
export const productToppingGroupItemOverrideUpdateSchema = baseProductToppingGroupItemOverrideUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductToppingGroupItemOverrideCreate = z.infer<typeof productToppingGroupItemOverrideCreateSchema>;
export type ProductToppingGroupItemOverrideUpdate = z.infer<typeof productToppingGroupItemOverrideUpdateSchema>;

// Re-export i18n and helpers
export {
  productToppingGroupItemOverrideI18n,
  getProductToppingGroupItemOverrideLabel,
  getProductToppingGroupItemOverrideFieldLabel,
  getProductToppingGroupItemOverrideFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductToppingGroupItemOverrideBase };
