/**
 * CustomerReturnInvoice Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CustomerReturnInvoice as CustomerReturnInvoiceBase } from './base/CustomerReturnInvoice';
import {
  baseCustomerReturnInvoiceSchemas,
  baseCustomerReturnInvoiceCreateSchema,
  baseCustomerReturnInvoiceUpdateSchema,
  customerReturnInvoiceI18n,
  getCustomerReturnInvoiceLabel,
  getCustomerReturnInvoiceFieldLabel,
  getCustomerReturnInvoiceFieldPlaceholder,
} from './base/CustomerReturnInvoice';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CustomerReturnInvoice extends CustomerReturnInvoiceBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const customerReturnInvoiceSchemas = { ...baseCustomerReturnInvoiceSchemas };
export const customerReturnInvoiceCreateSchema = baseCustomerReturnInvoiceCreateSchema;
export const customerReturnInvoiceUpdateSchema = baseCustomerReturnInvoiceUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CustomerReturnInvoiceCreate = z.infer<typeof customerReturnInvoiceCreateSchema>;
export type CustomerReturnInvoiceUpdate = z.infer<typeof customerReturnInvoiceUpdateSchema>;

// Re-export i18n and helpers
export {
  customerReturnInvoiceI18n,
  getCustomerReturnInvoiceLabel,
  getCustomerReturnInvoiceFieldLabel,
  getCustomerReturnInvoiceFieldPlaceholder,
};

// Re-export base type for internal use
export type { CustomerReturnInvoiceBase };
