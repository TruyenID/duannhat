/**
 * Organization Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Organization as OrganizationBase } from './base/Organization';
import {
  baseOrganizationSchemas,
  baseOrganizationCreateSchema,
  baseOrganizationUpdateSchema,
  organizationI18n,
  getOrganizationLabel,
  getOrganizationFieldLabel,
  getOrganizationFieldPlaceholder,
} from './base/Organization';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Organization extends OrganizationBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const organizationSchemas = { ...baseOrganizationSchemas };
export const organizationCreateSchema = baseOrganizationCreateSchema;
export const organizationUpdateSchema = baseOrganizationUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type OrganizationCreate = z.infer<typeof organizationCreateSchema>;
export type OrganizationUpdate = z.infer<typeof organizationUpdateSchema>;

// Re-export i18n and helpers
export {
  organizationI18n,
  getOrganizationLabel,
  getOrganizationFieldLabel,
  getOrganizationFieldPlaceholder,
};

// Re-export base type for internal use
export type { OrganizationBase };
