/**
 * OrderPaymentIntent Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { OrderPaymentIntent as OrderPaymentIntentBase } from './base/OrderPaymentIntent';
import {
  baseOrderPaymentIntentSchemas,
  baseOrderPaymentIntentCreateSchema,
  baseOrderPaymentIntentUpdateSchema,
  orderPaymentIntentI18n,
  getOrderPaymentIntentLabel,
  getOrderPaymentIntentFieldLabel,
  getOrderPaymentIntentFieldPlaceholder,
} from './base/OrderPaymentIntent';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface OrderPaymentIntent extends OrderPaymentIntentBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const orderPaymentIntentSchemas = { ...baseOrderPaymentIntentSchemas };
export const orderPaymentIntentCreateSchema = baseOrderPaymentIntentCreateSchema;
export const orderPaymentIntentUpdateSchema = baseOrderPaymentIntentUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type OrderPaymentIntentCreate = z.infer<typeof orderPaymentIntentCreateSchema>;
export type OrderPaymentIntentUpdate = z.infer<typeof orderPaymentIntentUpdateSchema>;

// Re-export i18n and helpers
export {
  orderPaymentIntentI18n,
  getOrderPaymentIntentLabel,
  getOrderPaymentIntentFieldLabel,
  getOrderPaymentIntentFieldPlaceholder,
};

// Re-export base type for internal use
export type { OrderPaymentIntentBase };
