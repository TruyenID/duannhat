/**
 * TableSession Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { TableSession as TableSessionBase } from './base/TableSession';
import {
  baseTableSessionSchemas,
  baseTableSessionCreateSchema,
  baseTableSessionUpdateSchema,
  tableSessionI18n,
  getTableSessionLabel,
  getTableSessionFieldLabel,
  getTableSessionFieldPlaceholder,
} from './base/TableSession';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TableSession extends TableSessionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tableSessionSchemas = { ...baseTableSessionSchemas };
export const tableSessionCreateSchema = baseTableSessionCreateSchema;
export const tableSessionUpdateSchema = baseTableSessionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TableSessionCreate = z.infer<typeof tableSessionCreateSchema>;
export type TableSessionUpdate = z.infer<typeof tableSessionUpdateSchema>;

// Re-export i18n and helpers
export {
  tableSessionI18n,
  getTableSessionLabel,
  getTableSessionFieldLabel,
  getTableSessionFieldPlaceholder,
};

// Re-export base type for internal use
export type { TableSessionBase };
