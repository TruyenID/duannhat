/**
 * MaterialBatchItem Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MaterialBatchItem as MaterialBatchItemBase } from './base/MaterialBatchItem';
import {
  baseMaterialBatchItemSchemas,
  baseMaterialBatchItemCreateSchema,
  baseMaterialBatchItemUpdateSchema,
  materialBatchItemI18n,
  getMaterialBatchItemLabel,
  getMaterialBatchItemFieldLabel,
  getMaterialBatchItemFieldPlaceholder,
} from './base/MaterialBatchItem';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MaterialBatchItem extends MaterialBatchItemBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const materialBatchItemSchemas = { ...baseMaterialBatchItemSchemas };
export const materialBatchItemCreateSchema = baseMaterialBatchItemCreateSchema;
export const materialBatchItemUpdateSchema = baseMaterialBatchItemUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MaterialBatchItemCreate = z.infer<typeof materialBatchItemCreateSchema>;
export type MaterialBatchItemUpdate = z.infer<typeof materialBatchItemUpdateSchema>;

// Re-export i18n and helpers
export {
  materialBatchItemI18n,
  getMaterialBatchItemLabel,
  getMaterialBatchItemFieldLabel,
  getMaterialBatchItemFieldPlaceholder,
};

// Re-export base type for internal use
export type { MaterialBatchItemBase };
