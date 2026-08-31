/**
 * File Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { File as FileBase } from './base/File';
import {
  baseFileSchemas,
  baseFileCreateSchema,
  baseFileUpdateSchema,
  fileI18n,
  getFileLabel,
  getFileFieldLabel,
  getFileFieldPlaceholder,
} from './base/File';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface File extends FileBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const fileSchemas = { ...baseFileSchemas };
export const fileCreateSchema = baseFileCreateSchema;
export const fileUpdateSchema = baseFileUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type FileCreate = z.infer<typeof fileCreateSchema>;
export type FileUpdate = z.infer<typeof fileUpdateSchema>;

// Re-export i18n and helpers
export {
  fileI18n,
  getFileLabel,
  getFileFieldLabel,
  getFileFieldPlaceholder,
};

// Re-export base type for internal use
export type { FileBase };
