/**
 * ProductionOrderItem Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ProductionOrderItem as ProductionOrderItemBase } from './base/ProductionOrderItem';
import {
  baseProductionOrderItemSchemas,
  baseProductionOrderItemCreateSchema,
  baseProductionOrderItemUpdateSchema,
  productionOrderItemI18n,
  getProductionOrderItemLabel,
  getProductionOrderItemFieldLabel,
  getProductionOrderItemFieldPlaceholder,
} from './base/ProductionOrderItem';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ProductionOrderItem extends ProductionOrderItemBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productionOrderItemSchemas = { ...baseProductionOrderItemSchemas };
export const productionOrderItemCreateSchema = baseProductionOrderItemCreateSchema;
export const productionOrderItemUpdateSchema = baseProductionOrderItemUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductionOrderItemCreate = z.infer<typeof productionOrderItemCreateSchema>;
export type ProductionOrderItemUpdate = z.infer<typeof productionOrderItemUpdateSchema>;

// Re-export i18n and helpers
export {
  productionOrderItemI18n,
  getProductionOrderItemLabel,
  getProductionOrderItemFieldLabel,
  getProductionOrderItemFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductionOrderItemBase };
