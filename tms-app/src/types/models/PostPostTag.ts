/**
 * PostPostTag Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { PostPostTag as PostPostTagBase } from './base/PostPostTag';
import {
  basePostPostTagSchemas,
  basePostPostTagCreateSchema,
  basePostPostTagUpdateSchema,
  postPostTagI18n,
  getPostPostTagLabel,
  getPostPostTagFieldLabel,
  getPostPostTagFieldPlaceholder,
} from './base/PostPostTag';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PostPostTag extends PostPostTagBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const postPostTagSchemas = { ...basePostPostTagSchemas };
export const postPostTagCreateSchema = basePostPostTagCreateSchema;
export const postPostTagUpdateSchema = basePostPostTagUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PostPostTagCreate = z.infer<typeof postPostTagCreateSchema>;
export type PostPostTagUpdate = z.infer<typeof postPostTagUpdateSchema>;

// Re-export i18n and helpers
export {
  postPostTagI18n,
  getPostPostTagLabel,
  getPostPostTagFieldLabel,
  getPostPostTagFieldPlaceholder,
};

// Re-export base type for internal use
export type { PostPostTagBase };
