/**
 * PaymentAttempt Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentAttempt as PaymentAttemptBase } from './base/PaymentAttempt';
import {
  basePaymentAttemptSchemas,
  basePaymentAttemptCreateSchema,
  basePaymentAttemptUpdateSchema,
  paymentAttemptI18n,
  getPaymentAttemptLabel,
  getPaymentAttemptFieldLabel,
  getPaymentAttemptFieldPlaceholder,
} from './base/PaymentAttempt';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentAttempt extends PaymentAttemptBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentAttemptSchemas = { ...basePaymentAttemptSchemas };
export const paymentAttemptCreateSchema = basePaymentAttemptCreateSchema;
export const paymentAttemptUpdateSchema = basePaymentAttemptUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentAttemptCreate = z.infer<typeof paymentAttemptCreateSchema>;
export type PaymentAttemptUpdate = z.infer<typeof paymentAttemptUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentAttemptI18n,
  getPaymentAttemptLabel,
  getPaymentAttemptFieldLabel,
  getPaymentAttemptFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentAttemptBase };
