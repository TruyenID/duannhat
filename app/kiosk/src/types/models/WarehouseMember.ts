/**
 * WarehouseMember Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { WarehouseMember as WarehouseMemberBase } from './base/WarehouseMember';
import {
  baseWarehouseMemberSchemas,
  baseWarehouseMemberCreateSchema,
  baseWarehouseMemberUpdateSchema,
  warehouseMemberI18n,
  getWarehouseMemberLabel,
  getWarehouseMemberFieldLabel,
  getWarehouseMemberFieldPlaceholder,
} from './base/WarehouseMember';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface WarehouseMember extends WarehouseMemberBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const warehouseMemberSchemas = { ...baseWarehouseMemberSchemas };
export const warehouseMemberCreateSchema = baseWarehouseMemberCreateSchema;
export const warehouseMemberUpdateSchema = baseWarehouseMemberUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type WarehouseMemberCreate = z.infer<typeof warehouseMemberCreateSchema>;
export type WarehouseMemberUpdate = z.infer<typeof warehouseMemberUpdateSchema>;

// Re-export i18n and helpers
export {
  warehouseMemberI18n,
  getWarehouseMemberLabel,
  getWarehouseMemberFieldLabel,
  getWarehouseMemberFieldPlaceholder,
};

// Re-export base type for internal use
export type { WarehouseMemberBase };
