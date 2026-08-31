/**
 * RecallDrill Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { RecallDrill as RecallDrillBase } from './base/RecallDrill';
import {
  baseRecallDrillSchemas,
  baseRecallDrillCreateSchema,
  baseRecallDrillUpdateSchema,
  recallDrillI18n,
  getRecallDrillLabel,
  getRecallDrillFieldLabel,
  getRecallDrillFieldPlaceholder,
} from './base/RecallDrill';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface RecallDrill extends RecallDrillBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const recallDrillSchemas = { ...baseRecallDrillSchemas };
export const recallDrillCreateSchema = baseRecallDrillCreateSchema;
export const recallDrillUpdateSchema = baseRecallDrillUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type RecallDrillCreate = z.infer<typeof recallDrillCreateSchema>;
export type RecallDrillUpdate = z.infer<typeof recallDrillUpdateSchema>;

// Re-export i18n and helpers
export {
  recallDrillI18n,
  getRecallDrillLabel,
  getRecallDrillFieldLabel,
  getRecallDrillFieldPlaceholder,
};

// Re-export base type for internal use
export type { RecallDrillBase };
