/**
 * PaymentGatewayConnectionOption Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentGatewayConnectionOption as PaymentGatewayConnectionOptionBase } from './base/PaymentGatewayConnectionOption';
import {
  basePaymentGatewayConnectionOptionSchemas,
  basePaymentGatewayConnectionOptionCreateSchema,
  basePaymentGatewayConnectionOptionUpdateSchema,
  paymentGatewayConnectionOptionI18n,
  getPaymentGatewayConnectionOptionLabel,
  getPaymentGatewayConnectionOptionFieldLabel,
  getPaymentGatewayConnectionOptionFieldPlaceholder,
} from './base/PaymentGatewayConnectionOption';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentGatewayConnectionOption extends PaymentGatewayConnectionOptionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentGatewayConnectionOptionSchemas = { ...basePaymentGatewayConnectionOptionSchemas };
export const paymentGatewayConnectionOptionCreateSchema = basePaymentGatewayConnectionOptionCreateSchema;
export const paymentGatewayConnectionOptionUpdateSchema = basePaymentGatewayConnectionOptionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentGatewayConnectionOptionCreate = z.infer<typeof paymentGatewayConnectionOptionCreateSchema>;
export type PaymentGatewayConnectionOptionUpdate = z.infer<typeof paymentGatewayConnectionOptionUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentGatewayConnectionOptionI18n,
  getPaymentGatewayConnectionOptionLabel,
  getPaymentGatewayConnectionOptionFieldLabel,
  getPaymentGatewayConnectionOptionFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentGatewayConnectionOptionBase };
