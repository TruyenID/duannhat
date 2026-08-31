/**
 * Branch Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Branch as BranchBase } from './base/Branch';
import {
  baseBranchSchemas,
  baseBranchCreateSchema,
  baseBranchUpdateSchema,
  branchI18n,
  getBranchLabel,
  getBranchFieldLabel,
  getBranchFieldPlaceholder,
} from './base/Branch';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Branch extends BranchBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const branchSchemas = { ...baseBranchSchemas };
export const branchCreateSchema = baseBranchCreateSchema;
export const branchUpdateSchema = baseBranchUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type BranchCreate = z.infer<typeof branchCreateSchema>;
export type BranchUpdate = z.infer<typeof branchUpdateSchema>;

// Re-export i18n and helpers
export {
  branchI18n,
  getBranchLabel,
  getBranchFieldLabel,
  getBranchFieldPlaceholder,
};

// Re-export base type for internal use
export type { BranchBase };
