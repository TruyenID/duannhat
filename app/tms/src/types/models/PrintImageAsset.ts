/**
 * PrintImageAsset Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PrintImageAsset as PrintImageAssetBase } from './base/PrintImageAsset';
import {
  basePrintImageAssetSchemas,
  basePrintImageAssetCreateSchema,
  basePrintImageAssetUpdateSchema,
  printImageAssetI18n,
  getPrintImageAssetLabel,
  getPrintImageAssetFieldLabel,
  getPrintImageAssetFieldPlaceholder,
} from './base/PrintImageAsset';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PrintImageAsset extends PrintImageAssetBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const printImageAssetSchemas = { ...basePrintImageAssetSchemas };
export const printImageAssetCreateSchema = basePrintImageAssetCreateSchema;
export const printImageAssetUpdateSchema = basePrintImageAssetUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PrintImageAssetCreate = z.infer<typeof printImageAssetCreateSchema>;
export type PrintImageAssetUpdate = z.infer<typeof printImageAssetUpdateSchema>;

// Re-export i18n and helpers
export {
  printImageAssetI18n,
  getPrintImageAssetLabel,
  getPrintImageAssetFieldLabel,
  getPrintImageAssetFieldPlaceholder,
};

// Re-export base type for internal use
export type { PrintImageAssetBase };
