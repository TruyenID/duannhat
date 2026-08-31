/**
 * PrintImageRaster Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PrintImageRaster as PrintImageRasterBase } from './base/PrintImageRaster';
import {
  basePrintImageRasterSchemas,
  basePrintImageRasterCreateSchema,
  basePrintImageRasterUpdateSchema,
  printImageRasterI18n,
  getPrintImageRasterLabel,
  getPrintImageRasterFieldLabel,
  getPrintImageRasterFieldPlaceholder,
} from './base/PrintImageRaster';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PrintImageRaster extends PrintImageRasterBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const printImageRasterSchemas = { ...basePrintImageRasterSchemas };
export const printImageRasterCreateSchema = basePrintImageRasterCreateSchema;
export const printImageRasterUpdateSchema = basePrintImageRasterUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PrintImageRasterCreate = z.infer<typeof printImageRasterCreateSchema>;
export type PrintImageRasterUpdate = z.infer<typeof printImageRasterUpdateSchema>;

// Re-export i18n and helpers
export {
  printImageRasterI18n,
  getPrintImageRasterLabel,
  getPrintImageRasterFieldLabel,
  getPrintImageRasterFieldPlaceholder,
};

// Re-export base type for internal use
export type { PrintImageRasterBase };
