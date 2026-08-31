/**
 * StockLevel Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { StockLevel as StockLevelBase } from './base/StockLevel';
import {
  baseStockLevelSchemas,
  baseStockLevelCreateSchema,
  baseStockLevelUpdateSchema,
  stockLevelI18n,
  getStockLevelLabel,
  getStockLevelFieldLabel,
  getStockLevelFieldPlaceholder,
} from './base/StockLevel';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface StockLevel extends StockLevelBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const stockLevelSchemas = { ...baseStockLevelSchemas };
export const stockLevelCreateSchema = baseStockLevelCreateSchema;
export const stockLevelUpdateSchema = baseStockLevelUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type StockLevelCreate = z.infer<typeof stockLevelCreateSchema>;
export type StockLevelUpdate = z.infer<typeof stockLevelUpdateSchema>;

// Re-export i18n and helpers
export {
  stockLevelI18n,
  getStockLevelLabel,
  getStockLevelFieldLabel,
  getStockLevelFieldPlaceholder,
};

// Re-export base type for internal use
export type { StockLevelBase };
