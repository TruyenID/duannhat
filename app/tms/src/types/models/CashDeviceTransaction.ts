/**
 * CashDeviceTransaction Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CashDeviceTransaction as CashDeviceTransactionBase } from './base/CashDeviceTransaction';
import {
  baseCashDeviceTransactionSchemas,
  baseCashDeviceTransactionCreateSchema,
  baseCashDeviceTransactionUpdateSchema,
  cashDeviceTransactionI18n,
  getCashDeviceTransactionLabel,
  getCashDeviceTransactionFieldLabel,
  getCashDeviceTransactionFieldPlaceholder,
} from './base/CashDeviceTransaction';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CashDeviceTransaction extends CashDeviceTransactionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const cashDeviceTransactionSchemas = { ...baseCashDeviceTransactionSchemas };
export const cashDeviceTransactionCreateSchema = baseCashDeviceTransactionCreateSchema;
export const cashDeviceTransactionUpdateSchema = baseCashDeviceTransactionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CashDeviceTransactionCreate = z.infer<typeof cashDeviceTransactionCreateSchema>;
export type CashDeviceTransactionUpdate = z.infer<typeof cashDeviceTransactionUpdateSchema>;

// Re-export i18n and helpers
export {
  cashDeviceTransactionI18n,
  getCashDeviceTransactionLabel,
  getCashDeviceTransactionFieldLabel,
  getCashDeviceTransactionFieldPlaceholder,
};

// Re-export base type for internal use
export type { CashDeviceTransactionBase };
