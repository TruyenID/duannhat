/**
 * StockTransferItem Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { StockTransferItem as StockTransferItemBase } from './base/StockTransferItem';
import {
  baseStockTransferItemSchemas,
  baseStockTransferItemCreateSchema,
  baseStockTransferItemUpdateSchema,
  stockTransferItemI18n,
  getStockTransferItemLabel,
  getStockTransferItemFieldLabel,
  getStockTransferItemFieldPlaceholder,
} from './base/StockTransferItem';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface StockTransferItem extends StockTransferItemBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const stockTransferItemSchemas = { ...baseStockTransferItemSchemas };
export const stockTransferItemCreateSchema = baseStockTransferItemCreateSchema;
export const stockTransferItemUpdateSchema = baseStockTransferItemUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type StockTransferItemCreate = z.infer<typeof stockTransferItemCreateSchema>;
export type StockTransferItemUpdate = z.infer<typeof stockTransferItemUpdateSchema>;

// Re-export i18n and helpers
export {
  stockTransferItemI18n,
  getStockTransferItemLabel,
  getStockTransferItemFieldLabel,
  getStockTransferItemFieldPlaceholder,
};

// Re-export base type for internal use
export type { StockTransferItemBase };
