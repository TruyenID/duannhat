/**
 * RoleUserPivot Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { RoleUserPivot as RoleUserPivotBase } from './base/RoleUserPivot';
import {
  baseRoleUserPivotSchemas,
  baseRoleUserPivotCreateSchema,
  baseRoleUserPivotUpdateSchema,
  roleUserPivotI18n,
  getRoleUserPivotLabel,
  getRoleUserPivotFieldLabel,
  getRoleUserPivotFieldPlaceholder,
} from './base/RoleUserPivot';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface RoleUserPivot extends RoleUserPivotBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const roleUserPivotSchemas = { ...baseRoleUserPivotSchemas };
export const roleUserPivotCreateSchema = baseRoleUserPivotCreateSchema;
export const roleUserPivotUpdateSchema = baseRoleUserPivotUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type RoleUserPivotCreate = z.infer<typeof roleUserPivotCreateSchema>;
export type RoleUserPivotUpdate = z.infer<typeof roleUserPivotUpdateSchema>;

// Re-export i18n and helpers
export {
  roleUserPivotI18n,
  getRoleUserPivotLabel,
  getRoleUserPivotFieldLabel,
  getRoleUserPivotFieldPlaceholder,
};

// Re-export base type for internal use
export type { RoleUserPivotBase };
