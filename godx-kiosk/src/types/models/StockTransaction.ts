/**
 * StockTransaction Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { StockTransaction as StockTransactionBase } from './base/StockTransaction';
import {
  baseStockTransactionSchemas,
  baseStockTransactionCreateSchema,
  baseStockTransactionUpdateSchema,
  stockTransactionI18n,
  getStockTransactionLabel,
  getStockTransactionFieldLabel,
  getStockTransactionFieldPlaceholder,
} from './base/StockTransaction';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface StockTransaction extends StockTransactionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const stockTransactionSchemas = { ...baseStockTransactionSchemas };
export const stockTransactionCreateSchema = baseStockTransactionCreateSchema;
export const stockTransactionUpdateSchema = baseStockTransactionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type StockTransactionCreate = z.infer<typeof stockTransactionCreateSchema>;
export type StockTransactionUpdate = z.infer<typeof stockTransactionUpdateSchema>;

// Re-export i18n and helpers
export {
  stockTransactionI18n,
  getStockTransactionLabel,
  getStockTransactionFieldLabel,
  getStockTransactionFieldPlaceholder,
};

// Re-export base type for internal use
export type { StockTransactionBase };
