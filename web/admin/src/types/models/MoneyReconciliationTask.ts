/**
 * MoneyReconciliationTask Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { MoneyReconciliationTask as MoneyReconciliationTaskBase } from './base/MoneyReconciliationTask';
import {
  baseMoneyReconciliationTaskSchemas,
  baseMoneyReconciliationTaskCreateSchema,
  baseMoneyReconciliationTaskUpdateSchema,
  moneyReconciliationTaskI18n,
  getMoneyReconciliationTaskLabel,
  getMoneyReconciliationTaskFieldLabel,
  getMoneyReconciliationTaskFieldPlaceholder,
} from './base/MoneyReconciliationTask';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MoneyReconciliationTask extends MoneyReconciliationTaskBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const moneyReconciliationTaskSchemas = { ...baseMoneyReconciliationTaskSchemas };
export const moneyReconciliationTaskCreateSchema = baseMoneyReconciliationTaskCreateSchema;
export const moneyReconciliationTaskUpdateSchema = baseMoneyReconciliationTaskUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MoneyReconciliationTaskCreate = z.infer<typeof moneyReconciliationTaskCreateSchema>;
export type MoneyReconciliationTaskUpdate = z.infer<typeof moneyReconciliationTaskUpdateSchema>;

// Re-export i18n and helpers
export {
  moneyReconciliationTaskI18n,
  getMoneyReconciliationTaskLabel,
  getMoneyReconciliationTaskFieldLabel,
  getMoneyReconciliationTaskFieldPlaceholder,
};

// Re-export base type for internal use
export type { MoneyReconciliationTaskBase };
