/**
 * PaymentMethod Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentMethod as PaymentMethodBase } from './base/PaymentMethod';
import {
  basePaymentMethodSchemas,
  basePaymentMethodCreateSchema,
  basePaymentMethodUpdateSchema,
  paymentMethodI18n,
  getPaymentMethodLabel,
  getPaymentMethodFieldLabel,
  getPaymentMethodFieldPlaceholder,
} from './base/PaymentMethod';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentMethod extends PaymentMethodBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentMethodSchemas = { ...basePaymentMethodSchemas };
export const paymentMethodCreateSchema = basePaymentMethodCreateSchema;
export const paymentMethodUpdateSchema = basePaymentMethodUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentMethodCreate = z.infer<typeof paymentMethodCreateSchema>;
export type PaymentMethodUpdate = z.infer<typeof paymentMethodUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentMethodI18n,
  getPaymentMethodLabel,
  getPaymentMethodFieldLabel,
  getPaymentMethodFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentMethodBase };
