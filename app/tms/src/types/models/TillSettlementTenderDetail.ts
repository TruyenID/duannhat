/**
 * TillSettlementTenderDetail Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { TillSettlementTenderDetail as TillSettlementTenderDetailBase } from './base/TillSettlementTenderDetail';
import {
  baseTillSettlementTenderDetailSchemas,
  baseTillSettlementTenderDetailCreateSchema,
  baseTillSettlementTenderDetailUpdateSchema,
  tillSettlementTenderDetailI18n,
  getTillSettlementTenderDetailLabel,
  getTillSettlementTenderDetailFieldLabel,
  getTillSettlementTenderDetailFieldPlaceholder,
} from './base/TillSettlementTenderDetail';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TillSettlementTenderDetail extends TillSettlementTenderDetailBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tillSettlementTenderDetailSchemas = { ...baseTillSettlementTenderDetailSchemas };
export const tillSettlementTenderDetailCreateSchema = baseTillSettlementTenderDetailCreateSchema;
export const tillSettlementTenderDetailUpdateSchema = baseTillSettlementTenderDetailUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TillSettlementTenderDetailCreate = z.infer<typeof tillSettlementTenderDetailCreateSchema>;
export type TillSettlementTenderDetailUpdate = z.infer<typeof tillSettlementTenderDetailUpdateSchema>;

// Re-export i18n and helpers
export {
  tillSettlementTenderDetailI18n,
  getTillSettlementTenderDetailLabel,
  getTillSettlementTenderDetailFieldLabel,
  getTillSettlementTenderDetailFieldPlaceholder,
};

// Re-export base type for internal use
export type { TillSettlementTenderDetailBase };
