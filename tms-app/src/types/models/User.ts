/**
 * User Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { User as UserBase } from './base/User';
import {
  baseUserSchemas,
  baseUserCreateSchema,
  baseUserUpdateSchema,
  userI18n,
  getUserLabel,
  getUserFieldLabel,
  getUserFieldPlaceholder,
} from './base/User';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface User extends UserBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const userSchemas = { ...baseUserSchemas };
export const userCreateSchema = baseUserCreateSchema;
export const userUpdateSchema = baseUserUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type UserCreate = z.infer<typeof userCreateSchema>;
export type UserUpdate = z.infer<typeof userUpdateSchema>;

// Re-export i18n and helpers
export {
  userI18n,
  getUserLabel,
  getUserFieldLabel,
  getUserFieldPlaceholder,
};

// Re-export base type for internal use
export type { UserBase };
