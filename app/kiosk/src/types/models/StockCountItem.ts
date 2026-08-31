/**
 * StockCountItem Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { StockCountItem as StockCountItemBase } from './base/StockCountItem';
import {
  baseStockCountItemSchemas,
  baseStockCountItemCreateSchema,
  baseStockCountItemUpdateSchema,
  stockCountItemI18n,
  getStockCountItemLabel,
  getStockCountItemFieldLabel,
  getStockCountItemFieldPlaceholder,
} from './base/StockCountItem';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface StockCountItem extends StockCountItemBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const stockCountItemSchemas = { ...baseStockCountItemSchemas };
export const stockCountItemCreateSchema = baseStockCountItemCreateSchema;
export const stockCountItemUpdateSchema = baseStockCountItemUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type StockCountItemCreate = z.infer<typeof stockCountItemCreateSchema>;
export type StockCountItemUpdate = z.infer<typeof stockCountItemUpdateSchema>;

// Re-export i18n and helpers
export {
  stockCountItemI18n,
  getStockCountItemLabel,
  getStockCountItemFieldLabel,
  getStockCountItemFieldPlaceholder,
};

// Re-export base type for internal use
export type { StockCountItemBase };
