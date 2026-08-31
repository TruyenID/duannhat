/**
 * TillCashEvent Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { TillCashEvent as TillCashEventBase } from './base/TillCashEvent';
import {
  baseTillCashEventSchemas,
  baseTillCashEventCreateSchema,
  baseTillCashEventUpdateSchema,
  tillCashEventI18n,
  getTillCashEventLabel,
  getTillCashEventFieldLabel,
  getTillCashEventFieldPlaceholder,
} from './base/TillCashEvent';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TillCashEvent extends TillCashEventBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tillCashEventSchemas = { ...baseTillCashEventSchemas };
export const tillCashEventCreateSchema = baseTillCashEventCreateSchema;
export const tillCashEventUpdateSchema = baseTillCashEventUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TillCashEventCreate = z.infer<typeof tillCashEventCreateSchema>;
export type TillCashEventUpdate = z.infer<typeof tillCashEventUpdateSchema>;

// Re-export i18n and helpers
export {
  tillCashEventI18n,
  getTillCashEventLabel,
  getTillCashEventFieldLabel,
  getTillCashEventFieldPlaceholder,
};

// Re-export base type for internal use
export type { TillCashEventBase };
