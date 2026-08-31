/**
 * Permission Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Permission as PermissionBase } from './base/Permission';
import {
  basePermissionSchemas,
  basePermissionCreateSchema,
  basePermissionUpdateSchema,
  permissionI18n,
  getPermissionLabel,
  getPermissionFieldLabel,
  getPermissionFieldPlaceholder,
} from './base/Permission';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Permission extends PermissionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const permissionSchemas = { ...basePermissionSchemas };
export const permissionCreateSchema = basePermissionCreateSchema;
export const permissionUpdateSchema = basePermissionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PermissionCreate = z.infer<typeof permissionCreateSchema>;
export type PermissionUpdate = z.infer<typeof permissionUpdateSchema>;

// Re-export i18n and helpers
export {
  permissionI18n,
  getPermissionLabel,
  getPermissionFieldLabel,
  getPermissionFieldPlaceholder,
};

// Re-export base type for internal use
export type { PermissionBase };
