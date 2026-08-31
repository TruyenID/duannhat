/**
 * WorkstationLogRequest Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { WorkstationLogRequest as WorkstationLogRequestBase } from './base/WorkstationLogRequest';
import {
  baseWorkstationLogRequestSchemas,
  baseWorkstationLogRequestCreateSchema,
  baseWorkstationLogRequestUpdateSchema,
  workstationLogRequestI18n,
  getWorkstationLogRequestLabel,
  getWorkstationLogRequestFieldLabel,
  getWorkstationLogRequestFieldPlaceholder,
} from './base/WorkstationLogRequest';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface WorkstationLogRequest extends WorkstationLogRequestBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const workstationLogRequestSchemas = { ...baseWorkstationLogRequestSchemas };
export const workstationLogRequestCreateSchema = baseWorkstationLogRequestCreateSchema;
export const workstationLogRequestUpdateSchema = baseWorkstationLogRequestUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type WorkstationLogRequestCreate = z.infer<typeof workstationLogRequestCreateSchema>;
export type WorkstationLogRequestUpdate = z.infer<typeof workstationLogRequestUpdateSchema>;

// Re-export i18n and helpers
export {
  workstationLogRequestI18n,
  getWorkstationLogRequestLabel,
  getWorkstationLogRequestFieldLabel,
  getWorkstationLogRequestFieldPlaceholder,
};

// Re-export base type for internal use
export type { WorkstationLogRequestBase };
