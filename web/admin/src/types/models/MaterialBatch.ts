/**
 * MaterialBatch Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MaterialBatch as MaterialBatchBase } from './base/MaterialBatch';
import {
  baseMaterialBatchSchemas,
  baseMaterialBatchCreateSchema,
  baseMaterialBatchUpdateSchema,
  materialBatchI18n,
  getMaterialBatchLabel,
  getMaterialBatchFieldLabel,
  getMaterialBatchFieldPlaceholder,
} from './base/MaterialBatch';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MaterialBatch extends MaterialBatchBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const materialBatchSchemas = { ...baseMaterialBatchSchemas };
export const materialBatchCreateSchema = baseMaterialBatchCreateSchema;
export const materialBatchUpdateSchema = baseMaterialBatchUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MaterialBatchCreate = z.infer<typeof materialBatchCreateSchema>;
export type MaterialBatchUpdate = z.infer<typeof materialBatchUpdateSchema>;

// Re-export i18n and helpers
export {
  materialBatchI18n,
  getMaterialBatchLabel,
  getMaterialBatchFieldLabel,
  getMaterialBatchFieldPlaceholder,
};

// Re-export base type for internal use
export type { MaterialBatchBase };
