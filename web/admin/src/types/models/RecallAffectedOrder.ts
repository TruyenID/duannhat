/**
 * RecallAffectedOrder Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { RecallAffectedOrder as RecallAffectedOrderBase } from './base/RecallAffectedOrder';
import {
  baseRecallAffectedOrderSchemas,
  baseRecallAffectedOrderCreateSchema,
  baseRecallAffectedOrderUpdateSchema,
  recallAffectedOrderI18n,
  getRecallAffectedOrderLabel,
  getRecallAffectedOrderFieldLabel,
  getRecallAffectedOrderFieldPlaceholder,
} from './base/RecallAffectedOrder';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface RecallAffectedOrder extends RecallAffectedOrderBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const recallAffectedOrderSchemas = { ...baseRecallAffectedOrderSchemas };
export const recallAffectedOrderCreateSchema = baseRecallAffectedOrderCreateSchema;
export const recallAffectedOrderUpdateSchema = baseRecallAffectedOrderUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type RecallAffectedOrderCreate = z.infer<typeof recallAffectedOrderCreateSchema>;
export type RecallAffectedOrderUpdate = z.infer<typeof recallAffectedOrderUpdateSchema>;

// Re-export i18n and helpers
export {
  recallAffectedOrderI18n,
  getRecallAffectedOrderLabel,
  getRecallAffectedOrderFieldLabel,
  getRecallAffectedOrderFieldPlaceholder,
};

// Re-export base type for internal use
export type { RecallAffectedOrderBase };
