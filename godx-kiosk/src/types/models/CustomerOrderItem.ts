/**
 * CustomerOrderItem Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { CustomerOrderItem as CustomerOrderItemBase } from './base/CustomerOrderItem';
import {
  baseCustomerOrderItemSchemas,
  baseCustomerOrderItemCreateSchema,
  baseCustomerOrderItemUpdateSchema,
  customerOrderItemI18n,
  getCustomerOrderItemLabel,
  getCustomerOrderItemFieldLabel,
  getCustomerOrderItemFieldPlaceholder,
} from './base/CustomerOrderItem';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CustomerOrderItem extends CustomerOrderItemBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const customerOrderItemSchemas = { ...baseCustomerOrderItemSchemas };
export const customerOrderItemCreateSchema = baseCustomerOrderItemCreateSchema;
export const customerOrderItemUpdateSchema = baseCustomerOrderItemUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CustomerOrderItemCreate = z.infer<typeof customerOrderItemCreateSchema>;
export type CustomerOrderItemUpdate = z.infer<typeof customerOrderItemUpdateSchema>;

// Re-export i18n and helpers
export {
  customerOrderItemI18n,
  getCustomerOrderItemLabel,
  getCustomerOrderItemFieldLabel,
  getCustomerOrderItemFieldPlaceholder,
};

// Re-export base type for internal use
export type { CustomerOrderItemBase };
