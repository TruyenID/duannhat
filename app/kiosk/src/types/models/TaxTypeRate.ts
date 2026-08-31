/**
 * TaxTypeRate Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { TaxTypeRate as TaxTypeRateBase } from './base/TaxTypeRate';
import {
  baseTaxTypeRateSchemas,
  baseTaxTypeRateCreateSchema,
  baseTaxTypeRateUpdateSchema,
  taxTypeRateI18n,
  getTaxTypeRateLabel,
  getTaxTypeRateFieldLabel,
  getTaxTypeRateFieldPlaceholder,
} from './base/TaxTypeRate';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TaxTypeRate extends TaxTypeRateBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const taxTypeRateSchemas = { ...baseTaxTypeRateSchemas };
export const taxTypeRateCreateSchema = baseTaxTypeRateCreateSchema;
export const taxTypeRateUpdateSchema = baseTaxTypeRateUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TaxTypeRateCreate = z.infer<typeof taxTypeRateCreateSchema>;
export type TaxTypeRateUpdate = z.infer<typeof taxTypeRateUpdateSchema>;

// Re-export i18n and helpers
export {
  taxTypeRateI18n,
  getTaxTypeRateLabel,
  getTaxTypeRateFieldLabel,
  getTaxTypeRateFieldPlaceholder,
};

// Re-export base type for internal use
export type { TaxTypeRateBase };
