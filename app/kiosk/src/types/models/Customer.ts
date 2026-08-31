/**
 * Customer Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Customer as CustomerBase } from './base/Customer';
import {
  baseCustomerSchemas,
  baseCustomerCreateSchema,
  baseCustomerUpdateSchema,
  customerI18n,
  getCustomerLabel,
  getCustomerFieldLabel,
  getCustomerFieldPlaceholder,
} from './base/Customer';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Customer extends CustomerBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const customerSchemas = { ...baseCustomerSchemas };
export const customerCreateSchema = baseCustomerCreateSchema;
export const customerUpdateSchema = baseCustomerUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CustomerCreate = z.infer<typeof customerCreateSchema>;
export type CustomerUpdate = z.infer<typeof customerUpdateSchema>;

// Re-export i18n and helpers
export {
  customerI18n,
  getCustomerLabel,
  getCustomerFieldLabel,
  getCustomerFieldPlaceholder,
};

// Re-export base type for internal use
export type { CustomerBase };
