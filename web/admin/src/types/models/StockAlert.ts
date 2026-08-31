/**
 * StockAlert Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { StockAlert as StockAlertBase } from './base/StockAlert';
import {
  baseStockAlertSchemas,
  baseStockAlertCreateSchema,
  baseStockAlertUpdateSchema,
  stockAlertI18n,
  getStockAlertLabel,
  getStockAlertFieldLabel,
  getStockAlertFieldPlaceholder,
} from './base/StockAlert';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface StockAlert extends StockAlertBase {
  /**
   * Plan-024 — the matching `stock_levels.id` so the admin-web alerts
   * page can drive the inline threshold-edit sheet via
   * `PUT /stock-levels/{id}` without a separate lookup. Server-side
   * computed in StockAlertResource. `null` when the underlying
   * StockLevel row no longer exists (alert is historical).
   */
  stock_level_id?: string | null;
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const stockAlertSchemas = { ...baseStockAlertSchemas };
export const stockAlertCreateSchema = baseStockAlertCreateSchema;
export const stockAlertUpdateSchema = baseStockAlertUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type StockAlertCreate = z.infer<typeof stockAlertCreateSchema>;
export type StockAlertUpdate = z.infer<typeof stockAlertUpdateSchema>;

// Re-export i18n and helpers
export {
  stockAlertI18n,
  getStockAlertLabel,
  getStockAlertFieldLabel,
  getStockAlertFieldPlaceholder,
};

// Re-export base type for internal use
export type { StockAlertBase };
