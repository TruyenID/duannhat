/**
 * ZoneTemplate Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { ZoneTemplate as ZoneTemplateBase } from './base/ZoneTemplate';
import {
  baseZoneTemplateSchemas,
  baseZoneTemplateCreateSchema,
  baseZoneTemplateUpdateSchema,
  zoneTemplateI18n,
  getZoneTemplateLabel,
  getZoneTemplateFieldLabel,
  getZoneTemplateFieldPlaceholder,
} from './base/ZoneTemplate';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface ZoneTemplate extends ZoneTemplateBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const zoneTemplateSchemas = { ...baseZoneTemplateSchemas };
export const zoneTemplateCreateSchema = baseZoneTemplateCreateSchema;
export const zoneTemplateUpdateSchema = baseZoneTemplateUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type ZoneTemplateCreate = z.infer<typeof zoneTemplateCreateSchema>;
export type ZoneTemplateUpdate = z.infer<typeof zoneTemplateUpdateSchema>;

// Re-export i18n and helpers
export {
  zoneTemplateI18n,
  getZoneTemplateLabel,
  getZoneTemplateFieldLabel,
  getZoneTemplateFieldPlaceholder,
};

// Re-export base type for internal use
export type { ZoneTemplateBase };
