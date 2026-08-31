/**
 * IdentityInboxEntry Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { IdentityInboxEntry as IdentityInboxEntryBase } from './base/IdentityInboxEntry';
import {
  baseIdentityInboxEntrySchemas,
  baseIdentityInboxEntryCreateSchema,
  baseIdentityInboxEntryUpdateSchema,
  identityInboxEntryI18n,
  getIdentityInboxEntryLabel,
  getIdentityInboxEntryFieldLabel,
  getIdentityInboxEntryFieldPlaceholder,
} from './base/IdentityInboxEntry';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface IdentityInboxEntry extends IdentityInboxEntryBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const identityInboxEntrySchemas = { ...baseIdentityInboxEntrySchemas };
export const identityInboxEntryCreateSchema = baseIdentityInboxEntryCreateSchema;
export const identityInboxEntryUpdateSchema = baseIdentityInboxEntryUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type IdentityInboxEntryCreate = z.infer<typeof identityInboxEntryCreateSchema>;
export type IdentityInboxEntryUpdate = z.infer<typeof identityInboxEntryUpdateSchema>;

// Re-export i18n and helpers
export {
  identityInboxEntryI18n,
  getIdentityInboxEntryLabel,
  getIdentityInboxEntryFieldLabel,
  getIdentityInboxEntryFieldPlaceholder,
};

// Re-export base type for internal use
export type { IdentityInboxEntryBase };
