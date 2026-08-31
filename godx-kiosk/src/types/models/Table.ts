/**
 * Table Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Table as TableBase } from './base/Table';
import {
  baseTableSchemas,
  baseTableCreateSchema,
  baseTableUpdateSchema,
  tableI18n,
  getTableLabel,
  getTableFieldLabel,
  getTableFieldPlaceholder,
} from './base/Table';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Table extends TableBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tableSchemas = { ...baseTableSchemas };
export const tableCreateSchema = baseTableCreateSchema;
export const tableUpdateSchema = baseTableUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TableCreate = z.infer<typeof tableCreateSchema>;
export type TableUpdate = z.infer<typeof tableUpdateSchema>;

// Re-export i18n and helpers
export {
  tableI18n,
  getTableLabel,
  getTableFieldLabel,
  getTableFieldPlaceholder,
};

// Re-export base type for internal use
export type { TableBase };
