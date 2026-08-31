/**
 * PrintTemplate Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PrintTemplate as PrintTemplateBase } from './base/PrintTemplate';
import {
  basePrintTemplateSchemas,
  basePrintTemplateCreateSchema,
  basePrintTemplateUpdateSchema,
  printTemplateI18n,
  getPrintTemplateLabel,
  getPrintTemplateFieldLabel,
  getPrintTemplateFieldPlaceholder,
} from './base/PrintTemplate';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PrintTemplate extends PrintTemplateBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const printTemplateSchemas = { ...basePrintTemplateSchemas };
export const printTemplateCreateSchema = basePrintTemplateCreateSchema;
export const printTemplateUpdateSchema = basePrintTemplateUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PrintTemplateCreate = z.infer<typeof printTemplateCreateSchema>;
export type PrintTemplateUpdate = z.infer<typeof printTemplateUpdateSchema>;

// Re-export i18n and helpers
export {
  printTemplateI18n,
  getPrintTemplateLabel,
  getPrintTemplateFieldLabel,
  getPrintTemplateFieldPlaceholder,
};

// Re-export base type for internal use
export type { PrintTemplateBase };
