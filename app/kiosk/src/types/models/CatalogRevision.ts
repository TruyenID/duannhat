/**
 * CatalogRevision Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { CatalogRevision as CatalogRevisionBase } from './base/CatalogRevision';
import {
  baseCatalogRevisionSchemas,
  baseCatalogRevisionCreateSchema,
  baseCatalogRevisionUpdateSchema,
  catalogRevisionI18n,
  getCatalogRevisionLabel,
  getCatalogRevisionFieldLabel,
  getCatalogRevisionFieldPlaceholder,
} from './base/CatalogRevision';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface CatalogRevision extends CatalogRevisionBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const catalogRevisionSchemas = { ...baseCatalogRevisionSchemas };
export const catalogRevisionCreateSchema = baseCatalogRevisionCreateSchema;
export const catalogRevisionUpdateSchema = baseCatalogRevisionUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type CatalogRevisionCreate = z.infer<typeof catalogRevisionCreateSchema>;
export type CatalogRevisionUpdate = z.infer<typeof catalogRevisionUpdateSchema>;

// Re-export i18n and helpers
export {
  catalogRevisionI18n,
  getCatalogRevisionLabel,
  getCatalogRevisionFieldLabel,
  getCatalogRevisionFieldPlaceholder,
};

// Re-export base type for internal use
export type { CatalogRevisionBase };
