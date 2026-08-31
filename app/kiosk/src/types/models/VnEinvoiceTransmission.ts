/**
 * VnEinvoiceTransmission Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { VnEinvoiceTransmission as VnEinvoiceTransmissionBase } from './base/VnEinvoiceTransmission';
import {
  baseVnEinvoiceTransmissionSchemas,
  baseVnEinvoiceTransmissionCreateSchema,
  baseVnEinvoiceTransmissionUpdateSchema,
  vnEinvoiceTransmissionI18n,
  getVnEinvoiceTransmissionLabel,
  getVnEinvoiceTransmissionFieldLabel,
  getVnEinvoiceTransmissionFieldPlaceholder,
} from './base/VnEinvoiceTransmission';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface VnEinvoiceTransmission extends VnEinvoiceTransmissionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const vnEinvoiceTransmissionSchemas = { ...baseVnEinvoiceTransmissionSchemas };
export const vnEinvoiceTransmissionCreateSchema = baseVnEinvoiceTransmissionCreateSchema;
export const vnEinvoiceTransmissionUpdateSchema = baseVnEinvoiceTransmissionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type VnEinvoiceTransmissionCreate = z.infer<typeof vnEinvoiceTransmissionCreateSchema>;
export type VnEinvoiceTransmissionUpdate = z.infer<typeof vnEinvoiceTransmissionUpdateSchema>;

// Re-export i18n and helpers
export {
  vnEinvoiceTransmissionI18n,
  getVnEinvoiceTransmissionLabel,
  getVnEinvoiceTransmissionFieldLabel,
  getVnEinvoiceTransmissionFieldPlaceholder,
};

// Re-export base type for internal use
export type { VnEinvoiceTransmissionBase };
