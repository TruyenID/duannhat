/**
 * MenuAvailabilityEvent Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { MenuAvailabilityEvent as MenuAvailabilityEventBase } from './base/MenuAvailabilityEvent';
import {
  baseMenuAvailabilityEventSchemas,
  baseMenuAvailabilityEventCreateSchema,
  baseMenuAvailabilityEventUpdateSchema,
  menuAvailabilityEventI18n,
  getMenuAvailabilityEventLabel,
  getMenuAvailabilityEventFieldLabel,
  getMenuAvailabilityEventFieldPlaceholder,
} from './base/MenuAvailabilityEvent';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface MenuAvailabilityEvent extends MenuAvailabilityEventBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const menuAvailabilityEventSchemas = { ...baseMenuAvailabilityEventSchemas };
export const menuAvailabilityEventCreateSchema = baseMenuAvailabilityEventCreateSchema;
export const menuAvailabilityEventUpdateSchema = baseMenuAvailabilityEventUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type MenuAvailabilityEventCreate = z.infer<typeof menuAvailabilityEventCreateSchema>;
export type MenuAvailabilityEventUpdate = z.infer<typeof menuAvailabilityEventUpdateSchema>;

// Re-export i18n and helpers
export {
  menuAvailabilityEventI18n,
  getMenuAvailabilityEventLabel,
  getMenuAvailabilityEventFieldLabel,
  getMenuAvailabilityEventFieldPlaceholder,
};

// Re-export base type for internal use
export type { MenuAvailabilityEventBase };
