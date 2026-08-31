/**
 * Recall Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Recall as RecallBase } from './base/Recall';
import {
  baseRecallSchemas,
  baseRecallCreateSchema,
  baseRecallUpdateSchema,
  recallI18n,
  getRecallLabel,
  getRecallFieldLabel,
  getRecallFieldPlaceholder,
} from './base/Recall';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Recall extends RecallBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const recallSchemas = { ...baseRecallSchemas };
export const recallCreateSchema = baseRecallCreateSchema;
export const recallUpdateSchema = baseRecallUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type RecallCreate = z.infer<typeof recallCreateSchema>;
export type RecallUpdate = z.infer<typeof recallUpdateSchema>;

// Re-export i18n and helpers
export {
  recallI18n,
  getRecallLabel,
  getRecallFieldLabel,
  getRecallFieldPlaceholder,
};

// Re-export base type for internal use
export type { RecallBase };
