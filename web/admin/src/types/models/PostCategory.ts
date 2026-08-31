/**
 * PostCategory Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { PostCategory as PostCategoryBase } from './base/PostCategory';
import {
  basePostCategorySchemas,
  basePostCategoryCreateSchema,
  basePostCategoryUpdateSchema,
  postCategoryI18n,
  getPostCategoryLabel,
  getPostCategoryFieldLabel,
  getPostCategoryFieldPlaceholder,
} from './base/PostCategory';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PostCategory extends PostCategoryBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const postCategorySchemas = { ...basePostCategorySchemas };
export const postCategoryCreateSchema = basePostCategoryCreateSchema;
export const postCategoryUpdateSchema = basePostCategoryUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PostCategoryCreate = z.infer<typeof postCategoryCreateSchema>;
export type PostCategoryUpdate = z.infer<typeof postCategoryUpdateSchema>;

// Re-export i18n and helpers
export {
  postCategoryI18n,
  getPostCategoryLabel,
  getPostCategoryFieldLabel,
  getPostCategoryFieldPlaceholder,
};

// Re-export base type for internal use
export type { PostCategoryBase };
