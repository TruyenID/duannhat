/**
 * PaymentGatewayProvider Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentGatewayProvider as PaymentGatewayProviderBase } from './base/PaymentGatewayProvider';
import {
  basePaymentGatewayProviderSchemas,
  basePaymentGatewayProviderCreateSchema,
  basePaymentGatewayProviderUpdateSchema,
  paymentGatewayProviderI18n,
  getPaymentGatewayProviderLabel,
  getPaymentGatewayProviderFieldLabel,
  getPaymentGatewayProviderFieldPlaceholder,
} from './base/PaymentGatewayProvider';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentGatewayProvider extends PaymentGatewayProviderBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentGatewayProviderSchemas = { ...basePaymentGatewayProviderSchemas };
export const paymentGatewayProviderCreateSchema = basePaymentGatewayProviderCreateSchema;
export const paymentGatewayProviderUpdateSchema = basePaymentGatewayProviderUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentGatewayProviderCreate = z.infer<typeof paymentGatewayProviderCreateSchema>;
export type PaymentGatewayProviderUpdate = z.infer<typeof paymentGatewayProviderUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentGatewayProviderI18n,
  getPaymentGatewayProviderLabel,
  getPaymentGatewayProviderFieldLabel,
  getPaymentGatewayProviderFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentGatewayProviderBase };
