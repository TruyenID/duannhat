/**
 * Denomination Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { Denomination as DenominationBase } from './base/Denomination';
import {
  baseDenominationSchemas,
  baseDenominationCreateSchema,
  baseDenominationUpdateSchema,
  denominationI18n,
  getDenominationLabel,
  getDenominationFieldLabel,
  getDenominationFieldPlaceholder,
} from './base/Denomination';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Denomination extends DenominationBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const denominationSchemas = { ...baseDenominationSchemas };
export const denominationCreateSchema = baseDenominationCreateSchema;
export const denominationUpdateSchema = baseDenominationUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type DenominationCreate = z.infer<typeof denominationCreateSchema>;
export type DenominationUpdate = z.infer<typeof denominationUpdateSchema>;

// Re-export i18n and helpers
export {
  denominationI18n,
  getDenominationLabel,
  getDenominationFieldLabel,
  getDenominationFieldPlaceholder,
};

// Re-export base type for internal use
export type { DenominationBase };
