/**
 * StockTransactionItem Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { StockTransactionItem as StockTransactionItemBase } from './base/StockTransactionItem';
import {
  baseStockTransactionItemSchemas,
  baseStockTransactionItemCreateSchema,
  baseStockTransactionItemUpdateSchema,
  stockTransactionItemI18n,
  getStockTransactionItemLabel,
  getStockTransactionItemFieldLabel,
  getStockTransactionItemFieldPlaceholder,
} from './base/StockTransactionItem';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface StockTransactionItem extends StockTransactionItemBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const stockTransactionItemSchemas = { ...baseStockTransactionItemSchemas };
export const stockTransactionItemCreateSchema = baseStockTransactionItemCreateSchema;
export const stockTransactionItemUpdateSchema = baseStockTransactionItemUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type StockTransactionItemCreate = z.infer<typeof stockTransactionItemCreateSchema>;
export type StockTransactionItemUpdate = z.infer<typeof stockTransactionItemUpdateSchema>;

// Re-export i18n and helpers
export {
  stockTransactionItemI18n,
  getStockTransactionItemLabel,
  getStockTransactionItemFieldLabel,
  getStockTransactionItemFieldPlaceholder,
};

// Re-export base type for internal use
export type { StockTransactionItemBase };
