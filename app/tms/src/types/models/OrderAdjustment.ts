/**
 * OrderAdjustment Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { OrderAdjustment as OrderAdjustmentBase } from './base/OrderAdjustment';
import {
  baseOrderAdjustmentSchemas,
  baseOrderAdjustmentCreateSchema,
  baseOrderAdjustmentUpdateSchema,
  orderAdjustmentI18n,
  getOrderAdjustmentLabel,
  getOrderAdjustmentFieldLabel,
  getOrderAdjustmentFieldPlaceholder,
} from './base/OrderAdjustment';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface OrderAdjustment extends OrderAdjustmentBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const orderAdjustmentSchemas = { ...baseOrderAdjustmentSchemas };
export const orderAdjustmentCreateSchema = baseOrderAdjustmentCreateSchema;
export const orderAdjustmentUpdateSchema = baseOrderAdjustmentUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type OrderAdjustmentCreate = z.infer<typeof orderAdjustmentCreateSchema>;
export type OrderAdjustmentUpdate = z.infer<typeof orderAdjustmentUpdateSchema>;

// Re-export i18n and helpers
export {
  orderAdjustmentI18n,
  getOrderAdjustmentLabel,
  getOrderAdjustmentFieldLabel,
  getOrderAdjustmentFieldPlaceholder,
};

// Re-export base type for internal use
export type { OrderAdjustmentBase };
