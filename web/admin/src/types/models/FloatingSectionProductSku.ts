/**
 * FloatingSectionProductSku Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { FloatingSectionProductSku as FloatingSectionProductSkuBase } from './base/FloatingSectionProductSku';
import type { ProductSku } from './ProductSku';
import {
  baseFloatingSectionProductSkuSchemas,
  baseFloatingSectionProductSkuCreateSchema,
  baseFloatingSectionProductSkuUpdateSchema,
  floatingSectionProductSkuI18n,
  getFloatingSectionProductSkuLabel,
  getFloatingSectionProductSkuFieldLabel,
  getFloatingSectionProductSkuFieldPlaceholder,
} from './base/FloatingSectionProductSku';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface FloatingSectionProductSku extends Omit<FloatingSectionProductSkuBase, 'productSku'> {
  // Uses the editable ProductSku sibling (adds image_url) instead of the
  // omnify base one — see ProductSku.ts and FloatingSectionService::findById.
  productSku?: ProductSku;
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const floatingSectionProductSkuSchemas = { ...baseFloatingSectionProductSkuSchemas };
export const floatingSectionProductSkuCreateSchema = baseFloatingSectionProductSkuCreateSchema;
export const floatingSectionProductSkuUpdateSchema = baseFloatingSectionProductSkuUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type FloatingSectionProductSkuCreate = z.infer<typeof floatingSectionProductSkuCreateSchema>;
export type FloatingSectionProductSkuUpdate = z.infer<typeof floatingSectionProductSkuUpdateSchema>;

// Re-export i18n and helpers
export {
  floatingSectionProductSkuI18n,
  getFloatingSectionProductSkuLabel,
  getFloatingSectionProductSkuFieldLabel,
  getFloatingSectionProductSkuFieldPlaceholder,
};

// Re-export base type for internal use
export type { FloatingSectionProductSkuBase };
