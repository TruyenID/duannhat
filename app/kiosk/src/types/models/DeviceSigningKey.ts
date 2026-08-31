/**
 * DeviceSigningKey Model
 *
 * This file extends the auto-generated base interface.
 * You can add custom methods, computed properties, or override types/schemas here.
 * This file will NOT be overwritten by the generator.
 */

import type { z } from 'zod';
import type { DeviceSigningKey as DeviceSigningKeyBase } from './base/DeviceSigningKey';
import {
  baseDeviceSigningKeySchemas,
  baseDeviceSigningKeyCreateSchema,
  baseDeviceSigningKeyUpdateSchema,
  deviceSigningKeyI18n,
  getDeviceSigningKeyLabel,
  getDeviceSigningKeyFieldLabel,
  getDeviceSigningKeyFieldPlaceholder,
} from './base/DeviceSigningKey';

// ============================================================================
// Types (extend or re-export)
// ============================================================================

export interface DeviceSigningKey extends DeviceSigningKeyBase {
  // Add custom properties here
}

// ============================================================================
// Schemas (extend or re-export)
// ============================================================================

export const deviceSigningKeySchemas = { ...baseDeviceSigningKeySchemas };
export const deviceSigningKeyCreateSchema = baseDeviceSigningKeyCreateSchema;
export const deviceSigningKeyUpdateSchema = baseDeviceSigningKeyUpdateSchema;

// ============================================================================
// Types
// ============================================================================

export type DeviceSigningKeyCreate = z.infer<typeof deviceSigningKeyCreateSchema>;
export type DeviceSigningKeyUpdate = z.infer<typeof deviceSigningKeyUpdateSchema>;

// Re-export i18n and helpers
export {
  deviceSigningKeyI18n,
  getDeviceSigningKeyLabel,
  getDeviceSigningKeyFieldLabel,
  getDeviceSigningKeyFieldPlaceholder,
};

// Re-export base type for internal use
export type { DeviceSigningKeyBase };
