/**
 * OrderItemTopping Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { OrderItemTopping as OrderItemToppingBase } from './base/OrderItemTopping';
import {
  baseOrderItemToppingSchemas,
  baseOrderItemToppingCreateSchema,
  baseOrderItemToppingUpdateSchema,
  orderItemToppingI18n,
  getOrderItemToppingLabel,
  getOrderItemToppingFieldLabel,
  getOrderItemToppingFieldPlaceholder,
} from './base/OrderItemTopping';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface OrderItemTopping extends OrderItemToppingBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const orderItemToppingSchemas = { ...baseOrderItemToppingSchemas };
export const orderItemToppingCreateSchema = baseOrderItemToppingCreateSchema;
export const orderItemToppingUpdateSchema = baseOrderItemToppingUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type OrderItemToppingCreate = z.infer<typeof orderItemToppingCreateSchema>;
export type OrderItemToppingUpdate = z.infer<typeof orderItemToppingUpdateSchema>;

// Re-export i18n and helpers
export {
  orderItemToppingI18n,
  getOrderItemToppingLabel,
  getOrderItemToppingFieldLabel,
  getOrderItemToppingFieldPlaceholder,
};

// Re-export base type for internal use
export type { OrderItemToppingBase };
