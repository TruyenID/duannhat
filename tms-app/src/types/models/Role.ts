/**
 * Role Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Role as RoleBase } from './base/Role';
import {
  baseRoleSchemas,
  baseRoleCreateSchema,
  baseRoleUpdateSchema,
  roleI18n,
  getRoleLabel,
  getRoleFieldLabel,
  getRoleFieldPlaceholder,
} from './base/Role';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Role extends RoleBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const roleSchemas = { ...baseRoleSchemas };
export const roleCreateSchema = baseRoleCreateSchema;
export const roleUpdateSchema = baseRoleUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type RoleCreate = z.infer<typeof roleCreateSchema>;
export type RoleUpdate = z.infer<typeof roleUpdateSchema>;

// Re-export i18n and helpers
export {
  roleI18n,
  getRoleLabel,
  getRoleFieldLabel,
  getRoleFieldPlaceholder,
};

// Re-export base type for internal use
export type { RoleBase };
