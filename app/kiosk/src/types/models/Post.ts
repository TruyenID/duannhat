/**
 * Post Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { Post as PostBase } from './base/Post';
import {
  basePostSchemas,
  basePostCreateSchema,
  basePostUpdateSchema,
  postI18n,
  getPostLabel,
  getPostFieldLabel,
  getPostFieldPlaceholder,
} from './base/Post';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Post extends PostBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const postSchemas = { ...basePostSchemas };
export const postCreateSchema = basePostCreateSchema;
export const postUpdateSchema = basePostUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PostCreate = z.infer<typeof postCreateSchema>;
export type PostUpdate = z.infer<typeof postUpdateSchema>;

// Re-export i18n and helpers
export {
  postI18n,
  getPostLabel,
  getPostFieldLabel,
  getPostFieldPlaceholder,
};

// Re-export base type for internal use
export type { PostBase };
