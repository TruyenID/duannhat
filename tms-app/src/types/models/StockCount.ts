/**
 * StockCount Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { StockCount as StockCountBase } from './base/StockCount';
import {
  baseStockCountSchemas,
  baseStockCountCreateSchema,
  baseStockCountUpdateSchema,
  stockCountI18n,
  getStockCountLabel,
  getStockCountFieldLabel,
  getStockCountFieldPlaceholder,
} from './base/StockCount';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface StockCount extends StockCountBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const stockCountSchemas = { ...baseStockCountSchemas };
export const stockCountCreateSchema = baseStockCountCreateSchema;
export const stockCountUpdateSchema = baseStockCountUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type StockCountCreate = z.infer<typeof stockCountCreateSchema>;
export type StockCountUpdate = z.infer<typeof stockCountUpdateSchema>;

// Re-export i18n and helpers
export {
  stockCountI18n,
  getStockCountLabel,
  getStockCountFieldLabel,
  getStockCountFieldPlaceholder,
};

// Re-export base type for internal use
export type { StockCountBase };
