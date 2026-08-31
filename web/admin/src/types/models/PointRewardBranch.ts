/**
 * PointRewardBranch Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PointRewardBranch as PointRewardBranchBase } from './base/PointRewardBranch';
import {
  basePointRewardBranchSchemas,
  basePointRewardBranchCreateSchema,
  basePointRewardBranchUpdateSchema,
  pointRewardBranchI18n,
  getPointRewardBranchLabel,
  getPointRewardBranchFieldLabel,
  getPointRewardBranchFieldPlaceholder,
} from './base/PointRewardBranch';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PointRewardBranch extends PointRewardBranchBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const pointRewardBranchSchemas = { ...basePointRewardBranchSchemas };
export const pointRewardBranchCreateSchema = basePointRewardBranchCreateSchema;
export const pointRewardBranchUpdateSchema = basePointRewardBranchUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PointRewardBranchCreate = z.infer<typeof pointRewardBranchCreateSchema>;
export type PointRewardBranchUpdate = z.infer<typeof pointRewardBranchUpdateSchema>;

// Re-export i18n and helpers
export {
  pointRewardBranchI18n,
  getPointRewardBranchLabel,
  getPointRewardBranchFieldLabel,
  getPointRewardBranchFieldPlaceholder,
};

// Re-export base type for internal use
export type { PointRewardBranchBase };
