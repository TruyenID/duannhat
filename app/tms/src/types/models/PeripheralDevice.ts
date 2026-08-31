/**
 * PeripheralDevice Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { PeripheralDevice as PeripheralDeviceBase } from './base/PeripheralDevice';
import {
  basePeripheralDeviceSchemas,
  basePeripheralDeviceCreateSchema,
  basePeripheralDeviceUpdateSchema,
  peripheralDeviceI18n,
  getPeripheralDeviceLabel,
  getPeripheralDeviceFieldLabel,
  getPeripheralDeviceFieldPlaceholder,
} from './base/PeripheralDevice';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface PeripheralDevice extends PeripheralDeviceBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const peripheralDeviceSchemas = { ...basePeripheralDeviceSchemas };
export const peripheralDeviceCreateSchema = basePeripheralDeviceCreateSchema;
export const peripheralDeviceUpdateSchema = basePeripheralDeviceUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type PeripheralDeviceCreate = z.infer<typeof peripheralDeviceCreateSchema>;
export type PeripheralDeviceUpdate = z.infer<typeof peripheralDeviceUpdateSchema>;

// Re-export i18n and helpers
export {
  peripheralDeviceI18n,
  getPeripheralDeviceLabel,
  getPeripheralDeviceFieldLabel,
  getPeripheralDeviceFieldPlaceholder,
};

// Re-export base type for internal use
export type { PeripheralDeviceBase };
