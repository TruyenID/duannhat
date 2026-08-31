/**
 * PaymentProviderEvent Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentProviderEvent as PaymentProviderEventBase } from './base/PaymentProviderEvent';
import {
  basePaymentProviderEventSchemas,
  basePaymentProviderEventCreateSchema,
  basePaymentProviderEventUpdateSchema,
  paymentProviderEventI18n,
  getPaymentProviderEventLabel,
  getPaymentProviderEventFieldLabel,
  getPaymentProviderEventFieldPlaceholder,
} from './base/PaymentProviderEvent';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentProviderEvent extends PaymentProviderEventBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentProviderEventSchemas = { ...basePaymentProviderEventSchemas };
export const paymentProviderEventCreateSchema = basePaymentProviderEventCreateSchema;
export const paymentProviderEventUpdateSchema = basePaymentProviderEventUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentProviderEventCreate = z.infer<typeof paymentProviderEventCreateSchema>;
export type PaymentProviderEventUpdate = z.infer<typeof paymentProviderEventUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentProviderEventI18n,
  getPaymentProviderEventLabel,
  getPaymentProviderEventFieldLabel,
  getPaymentProviderEventFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentProviderEventBase };
