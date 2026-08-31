/**
 * Printer Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { Printer as PrinterBase } from './base/Printer';
import {
  basePrinterSchemas,
  basePrinterCreateSchema,
  basePrinterUpdateSchema,
  printerI18n,
  getPrinterLabel,
  getPrinterFieldLabel,
  getPrinterFieldPlaceholder,
} from './base/Printer';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Printer extends PrinterBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const printerSchemas = { ...basePrinterSchemas };
export const printerCreateSchema = basePrinterCreateSchema;
export const printerUpdateSchema = basePrinterUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PrinterCreate = z.infer<typeof printerCreateSchema>;
export type PrinterUpdate = z.infer<typeof printerUpdateSchema>;

// Re-export i18n and helpers
export {
  printerI18n,
  getPrinterLabel,
  getPrinterFieldLabel,
  getPrinterFieldPlaceholder,
};

// Re-export base type for internal use
export type { PrinterBase };
