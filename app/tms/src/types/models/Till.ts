/**
 * Till Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Till as TillBase } from './base/Till';
import {
  baseTillSchemas,
  baseTillCreateSchema,
  baseTillUpdateSchema,
  tillI18n,
  getTillLabel,
  getTillFieldLabel,
  getTillFieldPlaceholder,
} from './base/Till';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Till extends TillBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tillSchemas = { ...baseTillSchemas };
export const tillCreateSchema = baseTillCreateSchema;
export const tillUpdateSchema = baseTillUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TillCreate = z.infer<typeof tillCreateSchema>;
export type TillUpdate = z.infer<typeof tillUpdateSchema>;

// Re-export i18n and helpers
export {
  tillI18n,
  getTillLabel,
  getTillFieldLabel,
  getTillFieldPlaceholder,
};

// Re-export base type for internal use
export type { TillBase };
