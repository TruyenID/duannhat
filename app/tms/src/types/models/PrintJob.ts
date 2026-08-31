/**
 * PrintJob Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PrintJob as PrintJobBase } from './base/PrintJob';
import {
  basePrintJobSchemas,
  basePrintJobCreateSchema,
  basePrintJobUpdateSchema,
  printJobI18n,
  getPrintJobLabel,
  getPrintJobFieldLabel,
  getPrintJobFieldPlaceholder,
} from './base/PrintJob';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PrintJob extends PrintJobBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const printJobSchemas = { ...basePrintJobSchemas };
export const printJobCreateSchema = basePrintJobCreateSchema;
export const printJobUpdateSchema = basePrintJobUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PrintJobCreate = z.infer<typeof printJobCreateSchema>;
export type PrintJobUpdate = z.infer<typeof printJobUpdateSchema>;

// Re-export i18n and helpers
export {
  printJobI18n,
  getPrintJobLabel,
  getPrintJobFieldLabel,
  getPrintJobFieldPlaceholder,
};

// Re-export base type for internal use
export type { PrintJobBase };
