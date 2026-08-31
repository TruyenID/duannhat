/**
 * BranchFloatingSectionOverride Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { BranchFloatingSectionOverride as BranchFloatingSectionOverrideBase } from './base/BranchFloatingSectionOverride';
import {
  baseBranchFloatingSectionOverrideSchemas,
  baseBranchFloatingSectionOverrideCreateSchema,
  baseBranchFloatingSectionOverrideUpdateSchema,
  branchFloatingSectionOverrideI18n,
  getBranchFloatingSectionOverrideLabel,
  getBranchFloatingSectionOverrideFieldLabel,
  getBranchFloatingSectionOverrideFieldPlaceholder,
} from './base/BranchFloatingSectionOverride';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface BranchFloatingSectionOverride extends BranchFloatingSectionOverrideBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const branchFloatingSectionOverrideSchemas = { ...baseBranchFloatingSectionOverrideSchemas };
export const branchFloatingSectionOverrideCreateSchema = baseBranchFloatingSectionOverrideCreateSchema;
export const branchFloatingSectionOverrideUpdateSchema = baseBranchFloatingSectionOverrideUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type BranchFloatingSectionOverrideCreate = z.infer<typeof branchFloatingSectionOverrideCreateSchema>;
export type BranchFloatingSectionOverrideUpdate = z.infer<typeof branchFloatingSectionOverrideUpdateSchema>;

// Re-export i18n and helpers
export {
  branchFloatingSectionOverrideI18n,
  getBranchFloatingSectionOverrideLabel,
  getBranchFloatingSectionOverrideFieldLabel,
  getBranchFloatingSectionOverrideFieldPlaceholder,
};

// Re-export base type for internal use
export type { BranchFloatingSectionOverrideBase };
