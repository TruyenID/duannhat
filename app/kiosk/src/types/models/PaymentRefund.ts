/**
 * PaymentRefund Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentRefund as PaymentRefundBase } from './base/PaymentRefund';
import {
  basePaymentRefundSchemas,
  basePaymentRefundCreateSchema,
  basePaymentRefundUpdateSchema,
  paymentRefundI18n,
  getPaymentRefundLabel,
  getPaymentRefundFieldLabel,
  getPaymentRefundFieldPlaceholder,
} from './base/PaymentRefund';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentRefund extends PaymentRefundBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentRefundSchemas = { ...basePaymentRefundSchemas };
export const paymentRefundCreateSchema = basePaymentRefundCreateSchema;
export const paymentRefundUpdateSchema = basePaymentRefundUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentRefundCreate = z.infer<typeof paymentRefundCreateSchema>;
export type PaymentRefundUpdate = z.infer<typeof paymentRefundUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentRefundI18n,
  getPaymentRefundLabel,
  getPaymentRefundFieldLabel,
  getPaymentRefundFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentRefundBase };
