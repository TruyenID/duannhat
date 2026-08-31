/**
 * ShopPaymentOption Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { ShopPaymentOption as ShopPaymentOptionBase } from './base/ShopPaymentOption';
import {
  baseShopPaymentOptionSchemas,
  baseShopPaymentOptionCreateSchema,
  baseShopPaymentOptionUpdateSchema,
  shopPaymentOptionI18n,
  getShopPaymentOptionLabel,
  getShopPaymentOptionFieldLabel,
  getShopPaymentOptionFieldPlaceholder,
} from './base/ShopPaymentOption';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ShopPaymentOption extends ShopPaymentOptionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const shopPaymentOptionSchemas = { ...baseShopPaymentOptionSchemas };
export const shopPaymentOptionCreateSchema = baseShopPaymentOptionCreateSchema;
export const shopPaymentOptionUpdateSchema = baseShopPaymentOptionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ShopPaymentOptionCreate = z.infer<typeof shopPaymentOptionCreateSchema>;
export type ShopPaymentOptionUpdate = z.infer<typeof shopPaymentOptionUpdateSchema>;

// Re-export i18n and helpers
export {
  shopPaymentOptionI18n,
  getShopPaymentOptionLabel,
  getShopPaymentOptionFieldLabel,
  getShopPaymentOptionFieldPlaceholder,
};

// Re-export base type for internal use
export type { ShopPaymentOptionBase };
