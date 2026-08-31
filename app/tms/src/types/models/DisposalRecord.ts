/**
 * DisposalRecord Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { DisposalRecord as DisposalRecordBase } from './base/DisposalRecord';
import {
  baseDisposalRecordSchemas,
  baseDisposalRecordCreateSchema,
  baseDisposalRecordUpdateSchema,
  disposalRecordI18n,
  getDisposalRecordLabel,
  getDisposalRecordFieldLabel,
  getDisposalRecordFieldPlaceholder,
} from './base/DisposalRecord';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface DisposalRecord extends DisposalRecordBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const disposalRecordSchemas = { ...baseDisposalRecordSchemas };
export const disposalRecordCreateSchema = baseDisposalRecordCreateSchema;
export const disposalRecordUpdateSchema = baseDisposalRecordUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type DisposalRecordCreate = z.infer<typeof disposalRecordCreateSchema>;
export type DisposalRecordUpdate = z.infer<typeof disposalRecordUpdateSchema>;

// Re-export i18n and helpers
export {
  disposalRecordI18n,
  getDisposalRecordLabel,
  getDisposalRecordFieldLabel,
  getDisposalRecordFieldPlaceholder,
};

// Re-export base type for internal use
export type { DisposalRecordBase };
