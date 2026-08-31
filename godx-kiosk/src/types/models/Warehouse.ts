/**
 * Warehouse Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Warehouse as WarehouseBase } from './base/Warehouse';
import {
  baseWarehouseSchemas,
  baseWarehouseCreateSchema,
  baseWarehouseUpdateSchema,
  warehouseI18n,
  getWarehouseLabel,
  getWarehouseFieldLabel,
  getWarehouseFieldPlaceholder,
} from './base/Warehouse';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Warehouse extends WarehouseBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const warehouseSchemas = { ...baseWarehouseSchemas };
export const warehouseCreateSchema = baseWarehouseCreateSchema;
export const warehouseUpdateSchema = baseWarehouseUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type WarehouseCreate = z.infer<typeof warehouseCreateSchema>;
export type WarehouseUpdate = z.infer<typeof warehouseUpdateSchema>;

// Re-export i18n and helpers
export {
  warehouseI18n,
  getWarehouseLabel,
  getWarehouseFieldLabel,
  getWarehouseFieldPlaceholder,
};

// Re-export base type for internal use
export type { WarehouseBase };
