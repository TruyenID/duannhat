/**
 * Zone Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Zone as ZoneBase } from './base/Zone';
import {
  baseZoneSchemas,
  baseZoneCreateSchema,
  baseZoneUpdateSchema,
  zoneI18n,
  getZoneLabel,
  getZoneFieldLabel,
  getZoneFieldPlaceholder,
} from './base/Zone';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Zone extends ZoneBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const zoneSchemas = { ...baseZoneSchemas };
export const zoneCreateSchema = baseZoneCreateSchema;
export const zoneUpdateSchema = baseZoneUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ZoneCreate = z.infer<typeof zoneCreateSchema>;
export type ZoneUpdate = z.infer<typeof zoneUpdateSchema>;

// Re-export i18n and helpers
export {
  zoneI18n,
  getZoneLabel,
  getZoneFieldLabel,
  getZoneFieldPlaceholder,
};

// Re-export base type for internal use
export type { ZoneBase };
