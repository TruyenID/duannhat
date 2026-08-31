/**
 * CustomerPointEntry Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CustomerPointEntry as CustomerPointEntryBase } from './base/CustomerPointEntry';
import {
  baseCustomerPointEntrySchemas,
  baseCustomerPointEntryCreateSchema,
  baseCustomerPointEntryUpdateSchema,
  customerPointEntryI18n,
  getCustomerPointEntryLabel,
  getCustomerPointEntryFieldLabel,
  getCustomerPointEntryFieldPlaceholder,
} from './base/CustomerPointEntry';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CustomerPointEntry extends CustomerPointEntryBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const customerPointEntrySchemas = { ...baseCustomerPointEntrySchemas };
export const customerPointEntryCreateSchema = baseCustomerPointEntryCreateSchema;
export const customerPointEntryUpdateSchema = baseCustomerPointEntryUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CustomerPointEntryCreate = z.infer<typeof customerPointEntryCreateSchema>;
export type CustomerPointEntryUpdate = z.infer<typeof customerPointEntryUpdateSchema>;

// Re-export i18n and helpers
export {
  customerPointEntryI18n,
  getCustomerPointEntryLabel,
  getCustomerPointEntryFieldLabel,
  getCustomerPointEntryFieldPlaceholder,
};

// Re-export base type for internal use
export type { CustomerPointEntryBase };
