/**
 * PaymentGatewayConnection Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentGatewayConnection as PaymentGatewayConnectionBase } from './base/PaymentGatewayConnection';
import {
  basePaymentGatewayConnectionSchemas,
  basePaymentGatewayConnectionCreateSchema,
  basePaymentGatewayConnectionUpdateSchema,
  paymentGatewayConnectionI18n,
  getPaymentGatewayConnectionLabel,
  getPaymentGatewayConnectionFieldLabel,
  getPaymentGatewayConnectionFieldPlaceholder,
} from './base/PaymentGatewayConnection';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentGatewayConnection extends PaymentGatewayConnectionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentGatewayConnectionSchemas = { ...basePaymentGatewayConnectionSchemas };
export const paymentGatewayConnectionCreateSchema = basePaymentGatewayConnectionCreateSchema;
export const paymentGatewayConnectionUpdateSchema = basePaymentGatewayConnectionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentGatewayConnectionCreate = z.infer<typeof paymentGatewayConnectionCreateSchema>;
export type PaymentGatewayConnectionUpdate = z.infer<typeof paymentGatewayConnectionUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentGatewayConnectionI18n,
  getPaymentGatewayConnectionLabel,
  getPaymentGatewayConnectionFieldLabel,
  getPaymentGatewayConnectionFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentGatewayConnectionBase };
