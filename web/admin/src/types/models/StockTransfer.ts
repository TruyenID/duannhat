/**
 * StockTransfer Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { StockTransfer as StockTransferBase } from './base/StockTransfer';
import {
  baseStockTransferSchemas,
  baseStockTransferCreateSchema,
  baseStockTransferUpdateSchema,
  stockTransferI18n,
  getStockTransferLabel,
  getStockTransferFieldLabel,
  getStockTransferFieldPlaceholder,
} from './base/StockTransfer';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface StockTransfer extends StockTransferBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const stockTransferSchemas = { ...baseStockTransferSchemas };
export const stockTransferCreateSchema = baseStockTransferCreateSchema;
export const stockTransferUpdateSchema = baseStockTransferUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type StockTransferCreate = z.infer<typeof stockTransferCreateSchema>;
export type StockTransferUpdate = z.infer<typeof stockTransferUpdateSchema>;

// Re-export i18n and helpers
export {
  stockTransferI18n,
  getStockTransferLabel,
  getStockTransferFieldLabel,
  getStockTransferFieldPlaceholder,
};

// Re-export base type for internal use
export type { StockTransferBase };
