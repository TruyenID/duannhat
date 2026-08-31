/**
 * BranchScheduleOverride Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { BranchScheduleOverride as BranchScheduleOverrideBase } from './base/BranchScheduleOverride';
import {
  baseBranchScheduleOverrideSchemas,
  baseBranchScheduleOverrideCreateSchema,
  baseBranchScheduleOverrideUpdateSchema,
  branchScheduleOverrideI18n,
  getBranchScheduleOverrideLabel,
  getBranchScheduleOverrideFieldLabel,
  getBranchScheduleOverrideFieldPlaceholder,
} from './base/BranchScheduleOverride';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface BranchScheduleOverride extends BranchScheduleOverrideBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const branchScheduleOverrideSchemas = { ...baseBranchScheduleOverrideSchemas };
export const branchScheduleOverrideCreateSchema = baseBranchScheduleOverrideCreateSchema;
export const branchScheduleOverrideUpdateSchema = baseBranchScheduleOverrideUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type BranchScheduleOverrideCreate = z.infer<typeof branchScheduleOverrideCreateSchema>;
export type BranchScheduleOverrideUpdate = z.infer<typeof branchScheduleOverrideUpdateSchema>;

// Re-export i18n and helpers
export {
  branchScheduleOverrideI18n,
  getBranchScheduleOverrideLabel,
  getBranchScheduleOverrideFieldLabel,
  getBranchScheduleOverrideFieldPlaceholder,
};

// Re-export base type for internal use
export type { BranchScheduleOverrideBase };
