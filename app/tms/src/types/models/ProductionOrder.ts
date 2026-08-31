/**
 * ProductionOrder Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ProductionOrder as ProductionOrderBase } from './base/ProductionOrder';
import {
  baseProductionOrderSchemas,
  baseProductionOrderCreateSchema,
  baseProductionOrderUpdateSchema,
  productionOrderI18n,
  getProductionOrderLabel,
  getProductionOrderFieldLabel,
  getProductionOrderFieldPlaceholder,
} from './base/ProductionOrder';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ProductionOrder extends ProductionOrderBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const productionOrderSchemas = { ...baseProductionOrderSchemas };
export const productionOrderCreateSchema = baseProductionOrderCreateSchema;
export const productionOrderUpdateSchema = baseProductionOrderUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ProductionOrderCreate = z.infer<typeof productionOrderCreateSchema>;
export type ProductionOrderUpdate = z.infer<typeof productionOrderUpdateSchema>;

// Re-export i18n and helpers
export {
  productionOrderI18n,
  getProductionOrderLabel,
  getProductionOrderFieldLabel,
  getProductionOrderFieldPlaceholder,
};

// Re-export base type for internal use
export type { ProductionOrderBase };
