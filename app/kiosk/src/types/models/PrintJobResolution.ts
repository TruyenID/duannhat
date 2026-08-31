/**
 * PrintJobResolution Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PrintJobResolution as PrintJobResolutionBase } from './base/PrintJobResolution';
import {
  basePrintJobResolutionSchemas,
  basePrintJobResolutionCreateSchema,
  basePrintJobResolutionUpdateSchema,
  printJobResolutionI18n,
  getPrintJobResolutionLabel,
  getPrintJobResolutionFieldLabel,
  getPrintJobResolutionFieldPlaceholder,
} from './base/PrintJobResolution';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PrintJobResolution extends PrintJobResolutionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const printJobResolutionSchemas = { ...basePrintJobResolutionSchemas };
export const printJobResolutionCreateSchema = basePrintJobResolutionCreateSchema;
export const printJobResolutionUpdateSchema = basePrintJobResolutionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PrintJobResolutionCreate = z.infer<typeof printJobResolutionCreateSchema>;
export type PrintJobResolutionUpdate = z.infer<typeof printJobResolutionUpdateSchema>;

// Re-export i18n and helpers
export {
  printJobResolutionI18n,
  getPrintJobResolutionLabel,
  getPrintJobResolutionFieldLabel,
  getPrintJobResolutionFieldPlaceholder,
};

// Re-export base type for internal use
export type { PrintJobResolutionBase };
