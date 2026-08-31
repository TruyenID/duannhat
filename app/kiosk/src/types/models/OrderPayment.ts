/**
 * OrderPayment Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { OrderPayment as OrderPaymentBase } from './base/OrderPayment';
import {
  baseOrderPaymentSchemas,
  baseOrderPaymentCreateSchema,
  baseOrderPaymentUpdateSchema,
  orderPaymentI18n,
  getOrderPaymentLabel,
  getOrderPaymentFieldLabel,
  getOrderPaymentFieldPlaceholder,
} from './base/OrderPayment';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface OrderPayment extends OrderPaymentBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const orderPaymentSchemas = { ...baseOrderPaymentSchemas };
export const orderPaymentCreateSchema = baseOrderPaymentCreateSchema;
export const orderPaymentUpdateSchema = baseOrderPaymentUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type OrderPaymentCreate = z.infer<typeof orderPaymentCreateSchema>;
export type OrderPaymentUpdate = z.infer<typeof orderPaymentUpdateSchema>;

// Re-export i18n and helpers
export {
  orderPaymentI18n,
  getOrderPaymentLabel,
  getOrderPaymentFieldLabel,
  getOrderPaymentFieldPlaceholder,
};

// Re-export base type for internal use
export type { OrderPaymentBase };
