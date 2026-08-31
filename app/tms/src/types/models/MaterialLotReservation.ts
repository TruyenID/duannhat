/**
 * MaterialLotReservation Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { MaterialLotReservation as MaterialLotReservationBase } from './base/MaterialLotReservation';
import {
  baseMaterialLotReservationSchemas,
  baseMaterialLotReservationCreateSchema,
  baseMaterialLotReservationUpdateSchema,
  materialLotReservationI18n,
  getMaterialLotReservationLabel,
  getMaterialLotReservationFieldLabel,
  getMaterialLotReservationFieldPlaceholder,
} from './base/MaterialLotReservation';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MaterialLotReservation extends MaterialLotReservationBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const materialLotReservationSchemas = { ...baseMaterialLotReservationSchemas };
export const materialLotReservationCreateSchema = baseMaterialLotReservationCreateSchema;
export const materialLotReservationUpdateSchema = baseMaterialLotReservationUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MaterialLotReservationCreate = z.infer<typeof materialLotReservationCreateSchema>;
export type MaterialLotReservationUpdate = z.infer<typeof materialLotReservationUpdateSchema>;

// Re-export i18n and helpers
export {
  materialLotReservationI18n,
  getMaterialLotReservationLabel,
  getMaterialLotReservationFieldLabel,
  getMaterialLotReservationFieldPlaceholder,
};

// Re-export base type for internal use
export type { MaterialLotReservationBase };
