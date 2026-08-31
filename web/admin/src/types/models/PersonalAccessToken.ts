/**
 * PersonalAccessToken Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PersonalAccessToken as PersonalAccessTokenBase } from './base/PersonalAccessToken';
import {
  basePersonalAccessTokenSchemas,
  basePersonalAccessTokenCreateSchema,
  basePersonalAccessTokenUpdateSchema,
  personalAccessTokenI18n,
  getPersonalAccessTokenLabel,
  getPersonalAccessTokenFieldLabel,
  getPersonalAccessTokenFieldPlaceholder,
} from './base/PersonalAccessToken';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PersonalAccessToken extends PersonalAccessTokenBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const personalAccessTokenSchemas = { ...basePersonalAccessTokenSchemas };
export const personalAccessTokenCreateSchema = basePersonalAccessTokenCreateSchema;
export const personalAccessTokenUpdateSchema = basePersonalAccessTokenUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PersonalAccessTokenCreate = z.infer<typeof personalAccessTokenCreateSchema>;
export type PersonalAccessTokenUpdate = z.infer<typeof personalAccessTokenUpdateSchema>;

// Re-export i18n and helpers
export {
  personalAccessTokenI18n,
  getPersonalAccessTokenLabel,
  getPersonalAccessTokenFieldLabel,
  getPersonalAccessTokenFieldPlaceholder,
};

// Re-export base type for internal use
export type { PersonalAccessTokenBase };
