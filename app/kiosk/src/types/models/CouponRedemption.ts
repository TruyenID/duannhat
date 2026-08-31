/**
 * CouponRedemption Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CouponRedemption as CouponRedemptionBase } from './base/CouponRedemption';
import {
  baseCouponRedemptionSchemas,
  baseCouponRedemptionCreateSchema,
  baseCouponRedemptionUpdateSchema,
  couponRedemptionI18n,
  getCouponRedemptionLabel,
  getCouponRedemptionFieldLabel,
  getCouponRedemptionFieldPlaceholder,
} from './base/CouponRedemption';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CouponRedemption extends CouponRedemptionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const couponRedemptionSchemas = { ...baseCouponRedemptionSchemas };
export const couponRedemptionCreateSchema = baseCouponRedemptionCreateSchema;
export const couponRedemptionUpdateSchema = baseCouponRedemptionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CouponRedemptionCreate = z.infer<typeof couponRedemptionCreateSchema>;
export type CouponRedemptionUpdate = z.infer<typeof couponRedemptionUpdateSchema>;

// Re-export i18n and helpers
export {
  couponRedemptionI18n,
  getCouponRedemptionLabel,
  getCouponRedemptionFieldLabel,
  getCouponRedemptionFieldPlaceholder,
};

// Re-export base type for internal use
export type { CouponRedemptionBase };
