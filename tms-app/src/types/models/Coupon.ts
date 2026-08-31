/**
 * Coupon Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Coupon as CouponBase } from './base/Coupon';
import {
  baseCouponSchemas,
  baseCouponCreateSchema,
  baseCouponUpdateSchema,
  couponI18n,
  getCouponLabel,
  getCouponFieldLabel,
  getCouponFieldPlaceholder,
} from './base/Coupon';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Coupon extends CouponBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const couponSchemas = { ...baseCouponSchemas };
export const couponCreateSchema = baseCouponCreateSchema;
export const couponUpdateSchema = baseCouponUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CouponCreate = z.infer<typeof couponCreateSchema>;
export type CouponUpdate = z.infer<typeof couponUpdateSchema>;

// Re-export i18n and helpers
export {
  couponI18n,
  getCouponLabel,
  getCouponFieldLabel,
  getCouponFieldPlaceholder,
};

// Re-export base type for internal use
export type { CouponBase };
