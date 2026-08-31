/**
 * WorkstationLogRecord Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { WorkstationLogRecord as WorkstationLogRecordBase } from './base/WorkstationLogRecord';
import {
  baseWorkstationLogRecordSchemas,
  baseWorkstationLogRecordCreateSchema,
  baseWorkstationLogRecordUpdateSchema,
  workstationLogRecordI18n,
  getWorkstationLogRecordLabel,
  getWorkstationLogRecordFieldLabel,
  getWorkstationLogRecordFieldPlaceholder,
} from './base/WorkstationLogRecord';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface WorkstationLogRecord extends WorkstationLogRecordBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const workstationLogRecordSchemas = { ...baseWorkstationLogRecordSchemas };
export const workstationLogRecordCreateSchema = baseWorkstationLogRecordCreateSchema;
export const workstationLogRecordUpdateSchema = baseWorkstationLogRecordUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type WorkstationLogRecordCreate = z.infer<typeof workstationLogRecordCreateSchema>;
export type WorkstationLogRecordUpdate = z.infer<typeof workstationLogRecordUpdateSchema>;

// Re-export i18n and helpers
export {
  workstationLogRecordI18n,
  getWorkstationLogRecordLabel,
  getWorkstationLogRecordFieldLabel,
  getWorkstationLogRecordFieldPlaceholder,
};

// Re-export base type for internal use
export type { WorkstationLogRecordBase };
