/**
 * CustomerInvoice Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CustomerInvoice as CustomerInvoiceBase } from './base/CustomerInvoice';
import {
  baseCustomerInvoiceSchemas,
  baseCustomerInvoiceCreateSchema,
  baseCustomerInvoiceUpdateSchema,
  customerInvoiceI18n,
  getCustomerInvoiceLabel,
  getCustomerInvoiceFieldLabel,
  getCustomerInvoiceFieldPlaceholder,
} from './base/CustomerInvoice';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CustomerInvoice extends CustomerInvoiceBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const customerInvoiceSchemas = { ...baseCustomerInvoiceSchemas };
export const customerInvoiceCreateSchema = baseCustomerInvoiceCreateSchema;
export const customerInvoiceUpdateSchema = baseCustomerInvoiceUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CustomerInvoiceCreate = z.infer<typeof customerInvoiceCreateSchema>;
export type CustomerInvoiceUpdate = z.infer<typeof customerInvoiceUpdateSchema>;

// Re-export i18n and helpers
export {
  customerInvoiceI18n,
  getCustomerInvoiceLabel,
  getCustomerInvoiceFieldLabel,
  getCustomerInvoiceFieldPlaceholder,
};

// Re-export base type for internal use
export type { CustomerInvoiceBase };
