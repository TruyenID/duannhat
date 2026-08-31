/**
 * InvoiceCounter Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { InvoiceCounter as InvoiceCounterBase } from './base/InvoiceCounter';
import {
  baseInvoiceCounterSchemas,
  baseInvoiceCounterCreateSchema,
  baseInvoiceCounterUpdateSchema,
  invoiceCounterI18n,
  getInvoiceCounterLabel,
  getInvoiceCounterFieldLabel,
  getInvoiceCounterFieldPlaceholder,
} from './base/InvoiceCounter';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface InvoiceCounter extends InvoiceCounterBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const invoiceCounterSchemas = { ...baseInvoiceCounterSchemas };
export const invoiceCounterCreateSchema = baseInvoiceCounterCreateSchema;
export const invoiceCounterUpdateSchema = baseInvoiceCounterUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type InvoiceCounterCreate = z.infer<typeof invoiceCounterCreateSchema>;
export type InvoiceCounterUpdate = z.infer<typeof invoiceCounterUpdateSchema>;

// Re-export i18n and helpers
export {
  invoiceCounterI18n,
  getInvoiceCounterLabel,
  getInvoiceCounterFieldLabel,
  getInvoiceCounterFieldPlaceholder,
};

// Re-export base type for internal use
export type { InvoiceCounterBase };
