/**
 * TillSession Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { TillSession as TillSessionBase } from './base/TillSession';
import {
  baseTillSessionSchemas,
  baseTillSessionCreateSchema,
  baseTillSessionUpdateSchema,
  tillSessionI18n,
  getTillSessionLabel,
  getTillSessionFieldLabel,
  getTillSessionFieldPlaceholder,
} from './base/TillSession';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TillSession extends TillSessionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tillSessionSchemas = { ...baseTillSessionSchemas };
export const tillSessionCreateSchema = baseTillSessionCreateSchema;
export const tillSessionUpdateSchema = baseTillSessionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TillSessionCreate = z.infer<typeof tillSessionCreateSchema>;
export type TillSessionUpdate = z.infer<typeof tillSessionUpdateSchema>;

// Re-export i18n and helpers
export {
  tillSessionI18n,
  getTillSessionLabel,
  getTillSessionFieldLabel,
  getTillSessionFieldPlaceholder,
};

// Re-export base type for internal use
export type { TillSessionBase };
