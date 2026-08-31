/**
 * VnEinvoiceSetting Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { VnEinvoiceSetting as VnEinvoiceSettingBase } from './base/VnEinvoiceSetting';
import {
  baseVnEinvoiceSettingSchemas,
  baseVnEinvoiceSettingCreateSchema,
  baseVnEinvoiceSettingUpdateSchema,
  vnEinvoiceSettingI18n,
  getVnEinvoiceSettingLabel,
  getVnEinvoiceSettingFieldLabel,
  getVnEinvoiceSettingFieldPlaceholder,
} from './base/VnEinvoiceSetting';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface VnEinvoiceSetting extends VnEinvoiceSettingBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const vnEinvoiceSettingSchemas = { ...baseVnEinvoiceSettingSchemas };
export const vnEinvoiceSettingCreateSchema = baseVnEinvoiceSettingCreateSchema;
export const vnEinvoiceSettingUpdateSchema = baseVnEinvoiceSettingUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type VnEinvoiceSettingCreate = z.infer<typeof vnEinvoiceSettingCreateSchema>;
export type VnEinvoiceSettingUpdate = z.infer<typeof vnEinvoiceSettingUpdateSchema>;

// Re-export i18n and helpers
export {
  vnEinvoiceSettingI18n,
  getVnEinvoiceSettingLabel,
  getVnEinvoiceSettingFieldLabel,
  getVnEinvoiceSettingFieldPlaceholder,
};

// Re-export base type for internal use
export type { VnEinvoiceSettingBase };
