/**
 * TillTenderType Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { TillTenderType as TillTenderTypeBase } from './base/TillTenderType';
import {
  baseTillTenderTypeSchemas,
  baseTillTenderTypeCreateSchema,
  baseTillTenderTypeUpdateSchema,
  tillTenderTypeI18n,
  getTillTenderTypeLabel,
  getTillTenderTypeFieldLabel,
  getTillTenderTypeFieldPlaceholder,
} from './base/TillTenderType';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TillTenderType extends TillTenderTypeBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tillTenderTypeSchemas = { ...baseTillTenderTypeSchemas };
export const tillTenderTypeCreateSchema = baseTillTenderTypeCreateSchema;
export const tillTenderTypeUpdateSchema = baseTillTenderTypeUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TillTenderTypeCreate = z.infer<typeof tillTenderTypeCreateSchema>;
export type TillTenderTypeUpdate = z.infer<typeof tillTenderTypeUpdateSchema>;

// Re-export i18n and helpers
export {
  tillTenderTypeI18n,
  getTillTenderTypeLabel,
  getTillTenderTypeFieldLabel,
  getTillTenderTypeFieldPlaceholder,
};

// Re-export base type for internal use
export type { TillTenderTypeBase };
