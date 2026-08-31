/**
 * StockMovement Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { StockMovement as StockMovementBase } from './base/StockMovement';
import {
  baseStockMovementSchemas,
  baseStockMovementCreateSchema,
  baseStockMovementUpdateSchema,
  stockMovementI18n,
  getStockMovementLabel,
  getStockMovementFieldLabel,
  getStockMovementFieldPlaceholder,
} from './base/StockMovement';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface StockMovement extends StockMovementBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const stockMovementSchemas = { ...baseStockMovementSchemas };
export const stockMovementCreateSchema = baseStockMovementCreateSchema;
export const stockMovementUpdateSchema = baseStockMovementUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type StockMovementCreate = z.infer<typeof stockMovementCreateSchema>;
export type StockMovementUpdate = z.infer<typeof stockMovementUpdateSchema>;

// Re-export i18n and helpers
export {
  stockMovementI18n,
  getStockMovementLabel,
  getStockMovementFieldLabel,
  getStockMovementFieldPlaceholder,
};

// Re-export base type for internal use
export type { StockMovementBase };
