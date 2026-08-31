/**
 * MaterialLot Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { MaterialLot as MaterialLotBase } from './base/MaterialLot';
import {
  baseMaterialLotSchemas,
  baseMaterialLotCreateSchema,
  baseMaterialLotUpdateSchema,
  materialLotI18n,
  getMaterialLotLabel,
  getMaterialLotFieldLabel,
  getMaterialLotFieldPlaceholder,
} from './base/MaterialLot';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MaterialLot extends MaterialLotBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const materialLotSchemas = { ...baseMaterialLotSchemas };
export const materialLotCreateSchema = baseMaterialLotCreateSchema;
export const materialLotUpdateSchema = baseMaterialLotUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MaterialLotCreate = z.infer<typeof materialLotCreateSchema>;
export type MaterialLotUpdate = z.infer<typeof materialLotUpdateSchema>;

// Re-export i18n and helpers
export {
  materialLotI18n,
  getMaterialLotLabel,
  getMaterialLotFieldLabel,
  getMaterialLotFieldPlaceholder,
};

// Re-export base type for internal use
export type { MaterialLotBase };
