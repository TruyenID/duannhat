/**
 * CouponBranch Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CouponBranch as CouponBranchBase } from './base/CouponBranch';
import {
  baseCouponBranchSchemas,
  baseCouponBranchCreateSchema,
  baseCouponBranchUpdateSchema,
  couponBranchI18n,
  getCouponBranchLabel,
  getCouponBranchFieldLabel,
  getCouponBranchFieldPlaceholder,
} from './base/CouponBranch';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CouponBranch extends CouponBranchBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const couponBranchSchemas = { ...baseCouponBranchSchemas };
export const couponBranchCreateSchema = baseCouponBranchCreateSchema;
export const couponBranchUpdateSchema = baseCouponBranchUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CouponBranchCreate = z.infer<typeof couponBranchCreateSchema>;
export type CouponBranchUpdate = z.infer<typeof couponBranchUpdateSchema>;

// Re-export i18n and helpers
export {
  couponBranchI18n,
  getCouponBranchLabel,
  getCouponBranchFieldLabel,
  getCouponBranchFieldPlaceholder,
};

// Re-export base type for internal use
export type { CouponBranchBase };
