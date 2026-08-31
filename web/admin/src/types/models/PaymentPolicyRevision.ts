/**
 * PaymentPolicyRevision Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentPolicyRevision as PaymentPolicyRevisionBase } from './base/PaymentPolicyRevision';
import {
  basePaymentPolicyRevisionSchemas,
  basePaymentPolicyRevisionCreateSchema,
  basePaymentPolicyRevisionUpdateSchema,
  paymentPolicyRevisionI18n,
  getPaymentPolicyRevisionLabel,
  getPaymentPolicyRevisionFieldLabel,
  getPaymentPolicyRevisionFieldPlaceholder,
} from './base/PaymentPolicyRevision';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentPolicyRevision extends PaymentPolicyRevisionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentPolicyRevisionSchemas = { ...basePaymentPolicyRevisionSchemas };
export const paymentPolicyRevisionCreateSchema = basePaymentPolicyRevisionCreateSchema;
export const paymentPolicyRevisionUpdateSchema = basePaymentPolicyRevisionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentPolicyRevisionCreate = z.infer<typeof paymentPolicyRevisionCreateSchema>;
export type PaymentPolicyRevisionUpdate = z.infer<typeof paymentPolicyRevisionUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentPolicyRevisionI18n,
  getPaymentPolicyRevisionLabel,
  getPaymentPolicyRevisionFieldLabel,
  getPaymentPolicyRevisionFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentPolicyRevisionBase };
