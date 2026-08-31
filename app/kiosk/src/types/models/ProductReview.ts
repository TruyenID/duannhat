/**
 * ProductReview Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { ProductReview as ProductReviewBase } from './base/ProductReview';
import {
  baseProductReviewSchemas,
  baseProductReviewCreateSchema,
  baseProductReviewUpdateSchema,
  productReviewI18n,
  getProductReviewLabel,
  getProductReviewFieldLabel,
  getProductReviewFieldPlaceholder,
} from './base/ProductReview';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ProductReview extends ProductReviewBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productReviewSchemas = { ...baseProductReviewSchemas };
export const productReviewCreateSchema = baseProductReviewCreateSchema;
export const productReviewUpdateSchema = baseProductReviewUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductReviewCreate = z.infer<typeof productReviewCreateSchema>;
export type ProductReviewUpdate = z.infer<typeof productReviewUpdateSchema>;

// Re-export i18n and helpers
export {
  productReviewI18n,
  getProductReviewLabel,
  getProductReviewFieldLabel,
  getProductReviewFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductReviewBase };
