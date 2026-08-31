/**
 * OrderAdjustmentAllocation Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { OrderAdjustmentAllocation as OrderAdjustmentAllocationBase } from './base/OrderAdjustmentAllocation';
import {
  baseOrderAdjustmentAllocationSchemas,
  baseOrderAdjustmentAllocationCreateSchema,
  baseOrderAdjustmentAllocationUpdateSchema,
  orderAdjustmentAllocationI18n,
  getOrderAdjustmentAllocationLabel,
  getOrderAdjustmentAllocationFieldLabel,
  getOrderAdjustmentAllocationFieldPlaceholder,
} from './base/OrderAdjustmentAllocation';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface OrderAdjustmentAllocation extends OrderAdjustmentAllocationBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const orderAdjustmentAllocationSchemas = { ...baseOrderAdjustmentAllocationSchemas };
export const orderAdjustmentAllocationCreateSchema = baseOrderAdjustmentAllocationCreateSchema;
export const orderAdjustmentAllocationUpdateSchema = baseOrderAdjustmentAllocationUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type OrderAdjustmentAllocationCreate = z.infer<typeof orderAdjustmentAllocationCreateSchema>;
export type OrderAdjustmentAllocationUpdate = z.infer<typeof orderAdjustmentAllocationUpdateSchema>;

// Re-export i18n and helpers
export {
  orderAdjustmentAllocationI18n,
  getOrderAdjustmentAllocationLabel,
  getOrderAdjustmentAllocationFieldLabel,
  getOrderAdjustmentAllocationFieldPlaceholder,
};

// Re-export base type for internal use
export type { OrderAdjustmentAllocationBase };
