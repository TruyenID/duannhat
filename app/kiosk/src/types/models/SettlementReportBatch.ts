/**
 * SettlementReportBatch Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { SettlementReportBatch as SettlementReportBatchBase } from './base/SettlementReportBatch';
import {
  baseSettlementReportBatchSchemas,
  baseSettlementReportBatchCreateSchema,
  baseSettlementReportBatchUpdateSchema,
  settlementReportBatchI18n,
  getSettlementReportBatchLabel,
  getSettlementReportBatchFieldLabel,
  getSettlementReportBatchFieldPlaceholder,
} from './base/SettlementReportBatch';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface SettlementReportBatch extends SettlementReportBatchBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const settlementReportBatchSchemas = { ...baseSettlementReportBatchSchemas };
export const settlementReportBatchCreateSchema = baseSettlementReportBatchCreateSchema;
export const settlementReportBatchUpdateSchema = baseSettlementReportBatchUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type SettlementReportBatchCreate = z.infer<typeof settlementReportBatchCreateSchema>;
export type SettlementReportBatchUpdate = z.infer<typeof settlementReportBatchUpdateSchema>;

// Re-export i18n and helpers
export {
  settlementReportBatchI18n,
  getSettlementReportBatchLabel,
  getSettlementReportBatchFieldLabel,
  getSettlementReportBatchFieldPlaceholder,
};

// Re-export base type for internal use
export type { SettlementReportBatchBase };
