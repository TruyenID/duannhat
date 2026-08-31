/**
 * PostBranch Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PostBranch as PostBranchBase } from './base/PostBranch';
import {
  basePostBranchSchemas,
  basePostBranchCreateSchema,
  basePostBranchUpdateSchema,
  postBranchI18n,
  getPostBranchLabel,
  getPostBranchFieldLabel,
  getPostBranchFieldPlaceholder,
} from './base/PostBranch';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PostBranch extends PostBranchBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const postBranchSchemas = { ...basePostBranchSchemas };
export const postBranchCreateSchema = basePostBranchCreateSchema;
export const postBranchUpdateSchema = basePostBranchUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PostBranchCreate = z.infer<typeof postBranchCreateSchema>;
export type PostBranchUpdate = z.infer<typeof postBranchUpdateSchema>;

// Re-export i18n and helpers
export {
  postBranchI18n,
  getPostBranchLabel,
  getPostBranchFieldLabel,
  getPostBranchFieldPlaceholder,
};

// Re-export base type for internal use
export type { PostBranchBase };
