/**
 * PaymentSettlement Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PaymentSettlement as PaymentSettlementBase } from './base/PaymentSettlement';
import {
  basePaymentSettlementSchemas,
  basePaymentSettlementCreateSchema,
  basePaymentSettlementUpdateSchema,
  paymentSettlementI18n,
  getPaymentSettlementLabel,
  getPaymentSettlementFieldLabel,
  getPaymentSettlementFieldPlaceholder,
} from './base/PaymentSettlement';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PaymentSettlement extends PaymentSettlementBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const paymentSettlementSchemas = { ...basePaymentSettlementSchemas };
export const paymentSettlementCreateSchema = basePaymentSettlementCreateSchema;
export const paymentSettlementUpdateSchema = basePaymentSettlementUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PaymentSettlementCreate = z.infer<typeof paymentSettlementCreateSchema>;
export type PaymentSettlementUpdate = z.infer<typeof paymentSettlementUpdateSchema>;

// Re-export i18n and helpers
export {
  paymentSettlementI18n,
  getPaymentSettlementLabel,
  getPaymentSettlementFieldLabel,
  getPaymentSettlementFieldPlaceholder,
};

// Re-export base type for internal use
export type { PaymentSettlementBase };
