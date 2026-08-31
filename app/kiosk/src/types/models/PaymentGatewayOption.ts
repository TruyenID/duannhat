/**
 * PaymentGatewayOption Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentGatewayOption as PaymentGatewayOptionBase } from './base/PaymentGatewayOption';
import {
  basePaymentGatewayOptionSchemas,
  basePaymentGatewayOptionCreateSchema,
  basePaymentGatewayOptionUpdateSchema,
  paymentGatewayOptionI18n,
  getPaymentGatewayOptionLabel,
  getPaymentGatewayOptionFieldLabel,
  getPaymentGatewayOptionFieldPlaceholder,
} from './base/PaymentGatewayOption';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentGatewayOption extends PaymentGatewayOptionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentGatewayOptionSchemas = { ...basePaymentGatewayOptionSchemas };
export const paymentGatewayOptionCreateSchema = basePaymentGatewayOptionCreateSchema;
export const paymentGatewayOptionUpdateSchema = basePaymentGatewayOptionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentGatewayOptionCreate = z.infer<typeof paymentGatewayOptionCreateSchema>;
export type PaymentGatewayOptionUpdate = z.infer<typeof paymentGatewayOptionUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentGatewayOptionI18n,
  getPaymentGatewayOptionLabel,
  getPaymentGatewayOptionFieldLabel,
  getPaymentGatewayOptionFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentGatewayOptionBase };
