/**
 * Device Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import { z } from 'zod';
import type { Device as DeviceBase } from './base/Device';
import {
  baseDeviceSchemas,
  baseDeviceCreateSchema,
  baseDeviceUpdateSchema,
  deviceI18n,
  getDeviceLabel,
  getDeviceFieldLabel,
  getDeviceFieldPlaceholder,
} from './base/Device';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface Device extends DeviceBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const deviceSchemas = { ...baseDeviceSchemas };
export const deviceCreateSchema = baseDeviceCreateSchema;
export const deviceUpdateSchema = baseDeviceUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type DeviceCreate = z.infer<typeof deviceCreateSchema>;
export type DeviceUpdate = z.infer<typeof deviceUpdateSchema>;

// Re-export i18n and helpers
export {
  deviceI18n,
  getDeviceLabel,
  getDeviceFieldLabel,
  getDeviceFieldPlaceholder,
};

// Re-export base type for internal use
export type { DeviceBase };
