/**
 * TaxType Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { TaxType as TaxTypeBase } from './base/TaxType';
import {
  baseTaxTypeSchemas,
  baseTaxTypeCreateSchema,
  baseTaxTypeUpdateSchema,
  taxTypeI18n,
  getTaxTypeLabel,
  getTaxTypeFieldLabel,
  getTaxTypeFieldPlaceholder,
} from './base/TaxType';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TaxType extends TaxTypeBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const taxTypeSchemas = { ...baseTaxTypeSchemas };
export const taxTypeCreateSchema = baseTaxTypeCreateSchema;
export const taxTypeUpdateSchema = baseTaxTypeUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TaxTypeCreate = z.infer<typeof taxTypeCreateSchema>;
export type TaxTypeUpdate = z.infer<typeof taxTypeUpdateSchema>;

// Re-export i18n and helpers
export {
  taxTypeI18n,
  getTaxTypeLabel,
  getTaxTypeFieldLabel,
  getTaxTypeFieldPlaceholder,
};

// Re-export base type for internal use
export type { TaxTypeBase };
