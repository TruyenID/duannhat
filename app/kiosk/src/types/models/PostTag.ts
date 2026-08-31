/**
 * PostTag Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PostTag as PostTagBase } from './base/PostTag';
import {
  basePostTagSchemas,
  basePostTagCreateSchema,
  basePostTagUpdateSchema,
  postTagI18n,
  getPostTagLabel,
  getPostTagFieldLabel,
  getPostTagFieldPlaceholder,
} from './base/PostTag';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PostTag extends PostTagBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const postTagSchemas = { ...basePostTagSchemas };
export const postTagCreateSchema = basePostTagCreateSchema;
export const postTagUpdateSchema = basePostTagUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PostTagCreate = z.infer<typeof postTagCreateSchema>;
export type PostTagUpdate = z.infer<typeof postTagUpdateSchema>;

// Re-export i18n and helpers
export {
  postTagI18n,
  getPostTagLabel,
  getPostTagFieldLabel,
  getPostTagFieldPlaceholder,
};

// Re-export base type for internal use
export type { PostTagBase };
