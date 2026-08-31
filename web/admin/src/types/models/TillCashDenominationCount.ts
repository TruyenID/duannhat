/**
 * TillCashDenominationCount Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { TillCashDenominationCount as TillCashDenominationCountBase } from './base/TillCashDenominationCount';
import {
  baseTillCashDenominationCountSchemas,
  baseTillCashDenominationCountCreateSchema,
  baseTillCashDenominationCountUpdateSchema,
  tillCashDenominationCountI18n,
  getTillCashDenominationCountLabel,
  getTillCashDenominationCountFieldLabel,
  getTillCashDenominationCountFieldPlaceholder,
} from './base/TillCashDenominationCount';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TillCashDenominationCount extends TillCashDenominationCountBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tillCashDenominationCountSchemas = { ...baseTillCashDenominationCountSchemas };
export const tillCashDenominationCountCreateSchema = baseTillCashDenominationCountCreateSchema;
export const tillCashDenominationCountUpdateSchema = baseTillCashDenominationCountUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TillCashDenominationCountCreate = z.infer<typeof tillCashDenominationCountCreateSchema>;
export type TillCashDenominationCountUpdate = z.infer<typeof tillCashDenominationCountUpdateSchema>;

// Re-export i18n and helpers
export {
  tillCashDenominationCountI18n,
  getTillCashDenominationCountLabel,
  getTillCashDenominationCountFieldLabel,
  getTillCashDenominationCountFieldPlaceholder,
};

// Re-export base type for internal use
export type { TillCashDenominationCountBase };
