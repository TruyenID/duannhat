/**
 * CustomerOrder Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { CustomerOrder as CustomerOrderBase } from './base/CustomerOrder';
import {
  baseCustomerOrderSchemas,
  baseCustomerOrderCreateSchema,
  baseCustomerOrderUpdateSchema,
  customerOrderI18n,
  getCustomerOrderLabel,
  getCustomerOrderFieldLabel,
  getCustomerOrderFieldPlaceholder,
} from './base/CustomerOrder';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CustomerOrder extends CustomerOrderBase {
  // #2041 — read-only API projections from order_conditions (not DB columns).
  discount_amount?: number;
  service_charge?: number;
  tax_amount?: number;
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const customerOrderSchemas = { ...baseCustomerOrderSchemas };
export const customerOrderCreateSchema = baseCustomerOrderCreateSchema;
export const customerOrderUpdateSchema = baseCustomerOrderUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CustomerOrderCreate = z.infer<typeof customerOrderCreateSchema>;
export type CustomerOrderUpdate = z.infer<typeof customerOrderUpdateSchema>;

// Re-export i18n and helpers
export {
  customerOrderI18n,
  getCustomerOrderLabel,
  getCustomerOrderFieldLabel,
  getCustomerOrderFieldPlaceholder,
};

// Re-export base type for internal use
export type { CustomerOrderBase };
