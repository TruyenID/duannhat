/**
 * VoidReason Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { VoidReason as VoidReasonBase } from './base/VoidReason';
import {
  baseVoidReasonSchemas,
  baseVoidReasonCreateSchema,
  baseVoidReasonUpdateSchema,
  voidReasonI18n,
  getVoidReasonLabel,
  getVoidReasonFieldLabel,
  getVoidReasonFieldPlaceholder,
} from './base/VoidReason';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface VoidReason extends VoidReasonBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const voidReasonSchemas = { ...baseVoidReasonSchemas };
export const voidReasonCreateSchema = baseVoidReasonCreateSchema;
export const voidReasonUpdateSchema = baseVoidReasonUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type VoidReasonCreate = z.infer<typeof voidReasonCreateSchema>;
export type VoidReasonUpdate = z.infer<typeof voidReasonUpdateSchema>;

// Re-export i18n and helpers
export {
  voidReasonI18n,
  getVoidReasonLabel,
  getVoidReasonFieldLabel,
  getVoidReasonFieldPlaceholder,
};

// Re-export base type for internal use
export type { VoidReasonBase };
