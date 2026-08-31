/**
 * RolePermission Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { RolePermission as RolePermissionBase } from './base/RolePermission';
import {
  baseRolePermissionSchemas,
  baseRolePermissionCreateSchema,
  baseRolePermissionUpdateSchema,
  rolePermissionI18n,
  getRolePermissionLabel,
  getRolePermissionFieldLabel,
  getRolePermissionFieldPlaceholder,
} from './base/RolePermission';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface RolePermission extends RolePermissionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const rolePermissionSchemas = { ...baseRolePermissionSchemas };
export const rolePermissionCreateSchema = baseRolePermissionCreateSchema;
export const rolePermissionUpdateSchema = baseRolePermissionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type RolePermissionCreate = z.infer<typeof rolePermissionCreateSchema>;
export type RolePermissionUpdate = z.infer<typeof rolePermissionUpdateSchema>;

// Re-export i18n and helpers
export {
  rolePermissionI18n,
  getRolePermissionLabel,
  getRolePermissionFieldLabel,
  getRolePermissionFieldPlaceholder,
};

// Re-export base type for internal use
export type { RolePermissionBase };
