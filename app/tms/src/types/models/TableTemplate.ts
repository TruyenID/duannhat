/**
 * TableTemplate Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { TableTemplate as TableTemplateBase } from './base/TableTemplate';
import {
  baseTableTemplateSchemas,
  baseTableTemplateCreateSchema,
  baseTableTemplateUpdateSchema,
  tableTemplateI18n,
  getTableTemplateLabel,
  getTableTemplateFieldLabel,
  getTableTemplateFieldPlaceholder,
} from './base/TableTemplate';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TableTemplate extends TableTemplateBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tableTemplateSchemas = { ...baseTableTemplateSchemas };
export const tableTemplateCreateSchema = baseTableTemplateCreateSchema;
export const tableTemplateUpdateSchema = baseTableTemplateUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TableTemplateCreate = z.infer<typeof tableTemplateCreateSchema>;
export type TableTemplateUpdate = z.infer<typeof tableTemplateUpdateSchema>;

// Re-export i18n and helpers
export {
  tableTemplateI18n,
  getTableTemplateLabel,
  getTableTemplateFieldLabel,
  getTableTemplateFieldPlaceholder,
};

// Re-export base type for internal use
export type { TableTemplateBase };
