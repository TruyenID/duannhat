/**
 * TableStatusChange Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { TableStatusChange as TableStatusChangeBase } from './base/TableStatusChange';
import {
  baseTableStatusChangeSchemas,
  baseTableStatusChangeCreateSchema,
  baseTableStatusChangeUpdateSchema,
  tableStatusChangeI18n,
  getTableStatusChangeLabel,
  getTableStatusChangeFieldLabel,
  getTableStatusChangeFieldPlaceholder,
} from './base/TableStatusChange';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface TableStatusChange extends TableStatusChangeBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const tableStatusChangeSchemas = { ...baseTableStatusChangeSchemas };
export const tableStatusChangeCreateSchema = baseTableStatusChangeCreateSchema;
export const tableStatusChangeUpdateSchema = baseTableStatusChangeUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type TableStatusChangeCreate = z.infer<typeof tableStatusChangeCreateSchema>;
export type TableStatusChangeUpdate = z.infer<typeof tableStatusChangeUpdateSchema>;

// Re-export i18n and helpers
export {
  tableStatusChangeI18n,
  getTableStatusChangeLabel,
  getTableStatusChangeFieldLabel,
  getTableStatusChangeFieldPlaceholder,
};

// Re-export base type for internal use
export type { TableStatusChangeBase };
