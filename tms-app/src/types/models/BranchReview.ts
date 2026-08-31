/**
 * BranchReview Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { BranchReview as BranchReviewBase } from './base/BranchReview';
import {
  baseBranchReviewSchemas,
  baseBranchReviewCreateSchema,
  baseBranchReviewUpdateSchema,
  branchReviewI18n,
  getBranchReviewLabel,
  getBranchReviewFieldLabel,
  getBranchReviewFieldPlaceholder,
} from './base/BranchReview';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface BranchReview extends BranchReviewBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const branchReviewSchemas = { ...baseBranchReviewSchemas };
export const branchReviewCreateSchema = baseBranchReviewCreateSchema;
export const branchReviewUpdateSchema = baseBranchReviewUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type BranchReviewCreate = z.infer<typeof branchReviewCreateSchema>;
export type BranchReviewUpdate = z.infer<typeof branchReviewUpdateSchema>;

// Re-export i18n and helpers
export {
  branchReviewI18n,
  getBranchReviewLabel,
  getBranchReviewFieldLabel,
  getBranchReviewFieldPlaceholder,
};

// Re-export base type for internal use
export type { BranchReviewBase };
